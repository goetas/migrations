<?php

namespace Doctrine\Migrations\Tests\Configuration;

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Exception\UnknownConfigurationValue;
use Doctrine\Migrations\Metadata\Storage\MetadataStorageConfigration;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public function testBase()
    {
        $storage = $this->createMock(MetadataStorageConfigration::class);

        $config = new Configuration();
        $config->addMigrationsDirectory('foo', 'bar');
        $config->addMigrationsDirectory('a', 'b');
        $config->setName('test migration');
        $config->setAllOrNothing(false);
        $config->setCheckDatabasePlatform(false);
        $config->setMetadataStorageConfiguration($storage);
        $config->setIsDryRun(true);
        $config->setCustomTemplate('aaa.php');


        self::assertSame([
            'foo' => 'bar',
            'a' => 'b',
        ], $config->getMigrationDirectories());
        self::assertSame('test migration', $config->getName());
        self::assertSame($storage, $config->getMetadataStorageConfiguration());
        self::assertFalse( $config->isAllOrNothing());
        self::assertFalse( $config->isDatabasePlatformChecked());
        self::assertTrue( $config->isDryRun());
        self::assertSame('aaa.php', $config->getCustomTemplate());

        self::assertFalse($config->areMigrationsOrganizedByYearAndMonth());
        self::assertFalse($config->areMigrationsOrganizedByYear());
    }

    public function testMigrationOrganizationByYear()
    {
        $config = new Configuration();
        $config->setMigrationOrganization(Configuration::VERSIONS_ORGANIZATION_BY_YEAR);


        self::assertFalse($config->areMigrationsOrganizedByYearAndMonth());
        self::assertTrue($config->areMigrationsOrganizedByYear());

    }

    public function testMigrationOrganizationByYearAndMonth()
    {
        $config = new Configuration();
        $config->setMigrationOrganization(Configuration::VERSIONS_ORGANIZATION_BY_YEAR_AND_MONTH);


        self::assertTrue($config->areMigrationsOrganizedByYearAndMonth());
        self::assertTrue($config->areMigrationsOrganizedByYear());

    }

    public function testMigrationOrganizationWithWrongValue()
    {
        $this->expectException(UnknownConfigurationValue::class);
        $config = new Configuration();
        $config->setMigrationOrganization('foo');
    }
}
