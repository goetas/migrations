<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tools\Console\Command;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\ConnectionLoader;
use Doctrine\Migrations\Tools\Console\Helper\ConfigurationHelper;
use Doctrine\Migrations\Tools\Console\Helper\ConfigurationHelperInterface;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use function escapeshellarg;
use function proc_open;
use function str_repeat;
use function strlen;

/**
 * The AbstractCommand class provides base functionality for the other migrations commands to extend from.
 */
abstract class AbstractCommand extends Command
{
    /** @var DependencyFactory|null */
    protected $dependencyFactory;

    public function initialize(
        InputInterface $input,
        OutputInterface $output
    ) : void {
        $this->initializeDependencies($input, $output);
        $this->dependencyFactory->getConfiguration()->validate();
    }

    public function __construct(?string $name = null, ?DependencyFactory $dependencyFactory = null)
    {
        parent::__construct($name);
        $this->dependencyFactory = $dependencyFactory;
    }

    protected function configure() : void
    {
        $this->addOption(
            'configuration',
            null,
            InputOption::VALUE_OPTIONAL,
            'The path to a migrations configuration file.'
        );

        $this->addOption(
            'db-configuration',
            null,
            InputOption::VALUE_OPTIONAL,
            'The path to a database connection configuration file.'
        );
    }

    protected function outputHeader(
        OutputInterface $output
    ) : void {
        $name = $this->dependencyFactory->getConfiguration()->getName();
        $name = $name ?? 'Doctrine Database Migrations';
        $name = str_repeat(' ', 20) . $name . str_repeat(' ', 20);
        $output->writeln('<question>' . str_repeat(' ', strlen($name)) . '</question>');
        $output->writeln('<question>' . $name . '</question>');
        $output->writeln('<question>' . str_repeat(' ', strlen($name)) . '</question>');
        $output->writeln('');
    }

    protected function initializeDependencies(
        InputInterface $input,
        OutputInterface $output
    ) : DependencyFactory {
        if ($this->dependencyFactory === null) {
            if ($this->hasConfigurationHelper()) {
                /** @var ConfigurationHelper $configHelper */
                $configHelper = $this->getHelperSet()->get('configuration');
            } else {
                $configHelper = new ConfigurationHelper();
            }

            $configuration = $configHelper->getConfiguration($input);
            $connection    = (new ConnectionLoader())
                ->getConnection($input, $this->getHelperSet());

            $em = null;
            if ($this->getHelperSet()->has('em') && $this->getHelperSet()->get('em') instanceof EntityManagerHelper) {
                $em = $this->getHelperSet()->get('em')->getEntityManager();
            }

            $logger                  = new ConsoleLogger($output);
            $this->dependencyFactory = new DependencyFactory($configuration, $connection, $em, $logger);
        }

        return $this->dependencyFactory;
    }

    protected function askConfirmation(
        string $question,
        InputInterface $input,
        OutputInterface $output
    ) : bool {
        return $this->getHelper('question')->ask(
            $input,
            $output,
            new ConfirmationQuestion($question)
        );
    }

    protected function canExecute(
        string $question,
        InputInterface $input,
        OutputInterface $output
    ) : bool {
        return ! $input->isInteractive() || $this->askConfirmation($question, $input, $output);
    }

    protected function procOpen(string $editorCommand, string $path) : void
    {
        proc_open($editorCommand . ' ' . escapeshellarg($path), [], $pipes);
    }

    private function hasConfigurationHelper() : bool
    {
        /** @var HelperSet|null $helperSet */
        $helperSet = $this->getHelperSet();

        if ($helperSet === null) {
            return false;
        }

        if (! $helperSet->has('configuration')) {
            return false;
        }

        return $helperSet->get('configuration') instanceof ConfigurationHelperInterface;
    }
}
