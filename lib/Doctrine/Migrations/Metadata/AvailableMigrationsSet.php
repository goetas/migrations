<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Metadata;

use Doctrine\Migrations\Version\Version;
use function array_filter;
use function array_values;
use function count;

class AvailableMigrationsSet
{
    /** @var AvailableMigration[] */
    private $items = [];

    public function __construct(array $items)
    {
        $this->items = array_values($items);
    }

    /**
     * @return AvailableMigration[]
     */
    public function getItems() : array
    {
        return $this->items;
    }

    public function getFirst(int $offset = 0) : ?AvailableMigration
    {
        return $this->items[$offset] ?? null;
    }

    public function getLast(int $offset = 0) : ?AvailableMigration
    {
        return $this->items[count($this->items)-1-$offset] ?? null;
    }

    public function getMigration(Version $version) : ?AvailableMigration
    {
        foreach ($this->items as $migration) {
            if ($migration->getVersion() == $version) {
                return $migration;
            }
        }

        return null;
    }

    public function getNewMigrations(ExecutedMigrationsSet $executedMigrationsSet) : AvailableMigrationsSet
    {
        return new AvailableMigrationsSet(array_filter($this->items, static function (AvailableMigration $migrationInfo) use ($executedMigrationsSet) {
            return $executedMigrationsSet->getMigration($migrationInfo->getVersion());
        }));
    }
}
