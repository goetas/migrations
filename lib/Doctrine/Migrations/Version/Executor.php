<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Version;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\Exception\SkipMigration;
use Doctrine\Migrations\Metadata\MetadataStorage;
use Doctrine\Migrations\Metadata\MigrationInfo;
use Doctrine\Migrations\Metadata\MigrationPlanItem;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\ParameterFormatterInterface;
use Doctrine\Migrations\Provider\SchemaDiffProviderInterface;
use Doctrine\Migrations\Stopwatch;
use Doctrine\Migrations\Tools\BytesFormatter;
use Psr\Log\LoggerInterface;
use Throwable;
use function count;
use function ucfirst;

/**
 * The Executor class is responsible for executing a single migration version.
 *
 * @internal
 */
final class Executor implements ExecutorInterface
{
    /** @var Connection */
    private $connection;

    /** @var SchemaDiffProviderInterface */
    private $schemaProvider;

    /** @var ParameterFormatterInterface */
    private $parameterFormatter;

    /** @var Stopwatch */
    private $stopwatch;

    /** @var string[] */
    private $sql = [];

    /** @var mixed[] */
    private $params = [];

    /** @var mixed[] */
    private $types = [];

    /** @var MetadataStorage */
    private $metadataStorage;

    /** @var LoggerInterface */
    private $logger;

    /** @var EventDispatcher */
    private $dispatcher;

    public function __construct(
        MetadataStorage $metadataStorage,
        EventDispatcher $dispatcher,
        Connection $connection,
        SchemaDiffProviderInterface $schemaProvider,
        LoggerInterface $logger,
        ParameterFormatterInterface $parameterFormatter,
        Stopwatch $stopwatch
    ) {
        $this->connection         = $connection;
        $this->schemaProvider     = $schemaProvider;
        $this->parameterFormatter = $parameterFormatter;
        $this->stopwatch          = $stopwatch;
        $this->metadataStorage    = $metadataStorage;
        $this->logger             = $logger;
        $this->dispatcher         = $dispatcher;
    }

    /**
     * @return string[]
     */
    public function getSql() : array
    {
        return $this->sql;
    }

    /**
     * @return mixed[]
     */
    public function getParams() : array
    {
        return $this->params;
    }

    /**
     * @return mixed[]
     */
    public function getTypes() : array
    {
        return $this->types;
    }

    /**
     * @param mixed[] $params
     * @param mixed[] $types
     */
    public function addSql(string $sql, array $params = [], array $types = []) : void
    {
        $this->sql[] = $sql;

        if (count($params) === 0) {
            return;
        }

        $this->addQueryParams($params, $types);
    }

    public function execute(
        MigrationPlanItem $plan,
        MigratorConfiguration $configuration
    ) : ExecutionResult {
        $result = new ExecutionResult($plan);

        $this->startMigration($plan, $configuration);

        try {
            $this->executeMigration(
                $result,
                $configuration
            );

            $result->setSql($this->sql);
            $result->setParams($this->params);
            $result->setTypes($this->types);
        } catch (SkipMigration $e) {
            $result->setSkipped(true);

            $this->migrationEnd($e, $result, $configuration);
        } catch (Throwable $e) {
            $result->setError(true);
            $result->setException($e);

            $this->migrationEnd($e, $result, $configuration);

            throw $e;
        }

        return $result;
    }

    private function startMigration(
        MigrationPlanItem $plan,
        MigratorConfiguration $configuration
    ) : void {
        $this->sql    = [];
        $this->params = [];
        $this->types  = [];

        $this->dispatcher->dispatchVersionEvent(
            $plan->getInfo()->getVersion(),
            Events::onMigrationsVersionExecuting,
            $plan,
            $configuration->isDryRun()
        );

        if (! $configuration->isDryRun()) {
            $this->metadataStorage->start($plan);
        }

        if (! $plan->getMigration()->isTransactional()) {
            return;
        }

        // only start transaction if in transactional mode
        $this->connection->beginTransaction();
    }

    private function executeMigration(
        ExecutionResult $result,
        MigratorConfiguration $configuration
    ) : ExecutionResult {
        $stopwatchEvent = $this->stopwatch->start('execute');

        $plan      = $result->getPlan();
        $migration = $plan->getMigration();
        $info      = $plan->getInfo();
        $direction = $plan->getDirection();

        $result->setState(State::PRE);

        $fromSchema = $this->getFromSchema($configuration);

        $migration->{'pre' . ucfirst($direction)}($fromSchema);

        $this->logger->info(...$this->getMigrationHeader($info, $migration, $direction));

        $result->setState(State::EXEC);

        $toSchema = $this->schemaProvider->createToSchema($fromSchema);

        $result->setToSchema($toSchema);

        $migration->$direction($toSchema);

        foreach ($this->schemaProvider->getSqlDiffToMigrate($fromSchema, $toSchema) as $sql) {
            $this->addSql($sql);
        }

        if (count($this->sql) !== 0) {
            if (! $configuration->isDryRun()) {
                $this->executeResult($configuration);
            } else {
                foreach ($this->sql as $idx => $query) {
                    $this->outputSqlQuery($idx, $query);
                }
            }
        } else {
            $this->logger->warning('Migration {version} was executed but did not result in any SQL statements.', [
                'version' => $info->getVersion(),
            ]);
        }

        $result->setState(State::POST);

        $migration->{'post' . ucfirst($direction)}($toSchema);
        $stopwatchEvent->stop();

        $result->setTime($stopwatchEvent->getDuration());
        $result->setMemory($stopwatchEvent->getMemory());

        $info->setResult($result);

        if (! $configuration->isDryRun()) {
            $this->metadataStorage->complete($result);
        }

        $params = [
            'version' => $info->getVersion(),
            'time' => $stopwatchEvent->getDuration(),
            'memory' => BytesFormatter::formatBytes($stopwatchEvent->getMemory()),
            'direction' => $direction === Direction::UP ? 'migrated' : 'reverted',
        ];

        $this->logger->info('Migration {version} {direction} (took {time}ms, used {memory} memory)', $params);

        if ($migration->isTransactional()) {
            //commit only if running in transactional mode
            $this->connection->commit();
        }

        $result->setState(State::NONE);

        $this->dispatcher->dispatchVersionEvent(
            $result->getPlan()->getInfo()->getVersion(),
            Events::onMigrationsVersionExecuted,
            $result,
            $configuration->isDryRun()
        );

        return $result;
    }

    private function getMigrationHeader(MigrationInfo $version, AbstractMigration $migration, string $direction) : array
    {
        $versionInfo = $version->getVersion();
        $description = $migration->getDescription();

        if ($description !== '') {
            $versionInfo .= ' (' . $description . ')';
        }

        $params = ['version_name' => $versionInfo];

        if ($direction === Direction::UP) {
            return ['++ migrating {version_name}', $params];
        }

        return ['++ reverting {version_name}', $params];
    }

    private function migrationEnd(Throwable $e, ExecutionResult $result, MigratorConfiguration $configuration) : void
    {
        $info = $result->getPlan()->getInfo();

        if ($result->isSkipped()) {
            $this->logger->error(
                'Migration {version} skipped during %s. Reason {error}',
                [
                    'version' =>(string) $info->getVersion(),
                    'reason' => $e->getMessage(),
                    'state' => $result->getState(),
                ]
            );
        } elseif ($result->hasError()) {
            $this->logger->error(
                'Migration {version} failed during %s. Error {error}',
                [
                    'version' => (string) $info->getVersion(),
                    'error' => $e->getMessage(),
                    'state' => $result->getState(),
                ]
            );
        }

        $this->dispatcher->dispatchVersionEvent(
            $info->getVersion(),
            Events::onMigrationsVersionSkipped,
            $result,
            $configuration->isDryRun()
        );

        $migration = $result->getPlan()->getMigration();

        if ($migration->isTransactional()) {
            //only rollback transaction if in transactional mode
            $this->connection->rollBack();
        }

        if ($configuration->isDryRun() || $result->isSkipped()) {
            return;
        }

        $this->metadataStorage->complete($result);
    }

    private function executeResult(MigratorConfiguration $configuration) : void
    {
        foreach ($this->sql as $key => $query) {
            $stopwatchEvent = $this->stopwatch->start('query');

            $this->outputSqlQuery($key, $query);

            if (! isset($this->params[$key])) {
                $this->connection->executeQuery($query);
            } else {
                $this->connection->executeQuery($query, $this->params[$key], $this->types[$key]);
            }

            $stopwatchEvent->stop();

            if (! $configuration->getTimeAllQueries()) {
                continue;
            }

            $this->logger->info('{duration}ms', [
                'duration' => $stopwatchEvent->getDuration(),
            ]);
        }
    }

    /**
     * @param mixed[]|int $params
     * @param mixed[]|int $types
     */
    private function addQueryParams($params, $types) : void
    {
        $index                = count($this->sql) - 1;
        $this->params[$index] = $params;
        $this->types[$index]  = $types;
    }

    private function outputSqlQuery(int $idx, string $query) : void
    {
        $params = $this->parameterFormatter->formatParameters(
            $this->params[$idx] ?? [],
            $this->types[$idx] ?? []
        );

        $this->logger->info('{query} {params}', [
            'query' => $query,
            'params' => $params,
        ]);
    }

    private function getFromSchema(MigratorConfiguration $configuration) : Schema
    {
        // if we're in a dry run, use the from Schema instead of reading the schema from the database
        if ($configuration->isDryRun() && $configuration->getFromSchema() !== null) {
            return $configuration->getFromSchema();
        }

        return $this->schemaProvider->createFromSchema();
    }
}
