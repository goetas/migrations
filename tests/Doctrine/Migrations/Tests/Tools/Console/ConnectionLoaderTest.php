<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tests\Tools\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Sharding\PoolingShardConnection;
use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use Doctrine\Migrations\Configuration\Connection\Loader\Exception\InvalidConfiguration;
use Doctrine\Migrations\Tools\Console\ConnectionLoader;
use Doctrine\Migrations\Tools\Console\Exception\ConnectionNotSpecified;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use function chdir;
use function getcwd;

class ConnectionLoaderTest extends TestCase
{
    /** @var ConnectionLoader */
    private $connectionLoader;

    public function testGetConnectionFromArray() : void
    {
        $helperSet = $this->createMock(HelperSet::class);

        $dir = getcwd() ?: '.';
        chdir(__DIR__);
        try {
            $conn = $this->connectionLoader->getConnection('_files/sqlite-connection.php', null, $helperSet);
            self::assertInstanceOf(SqlitePlatform::class, $conn->getDatabasePlatform());
        } finally {
            chdir($dir);
        }
    }

    public function testGetConnectionFromArrayNotFound() : void
    {
        $this->expectException(ConnectionNotSpecified::class);
        $helperSet = $this->createMock(HelperSet::class);

        $dir = getcwd()?: '.';
        chdir(__DIR__);
        try {
            $this->connectionLoader->getConnection(__DIR__ . '/_files/wrong.php', null, $helperSet);
        } finally {
            chdir($dir);
        }
    }

    public function testGetConnectionFromArrayFromFallbackFile() : void
    {
        $helperSet = $this->createMock(HelperSet::class);

        $dir = getcwd()?: '.';
        chdir(__DIR__ . '/_files');
        try {
            $conn = $this->connectionLoader->getConnection(null, null, $helperSet);
            self::assertInstanceOf(SqlitePlatform::class, $conn->getDatabasePlatform());
        } finally {
            chdir($dir);
        }
    }

    public function testGetConnectionFromHelper() : void
    {
        $connection = $this->createMock(Connection::class);

        $helper = $this->createMock(ConnectionHelper::class);
        $helper->expects(self::once())->method('getConnection')->willReturn($connection);

        $helperSet = new HelperSet();
        $helperSet->set($helper, 'connection');

        $dir = getcwd()?: '.';
        chdir(__DIR__);
        try {
            self::assertSame($connection, $this->connectionLoader->getConnection(null, null, $helperSet));
        } finally {
            chdir($dir);
        }
    }

    public function testGetShardedConnectionNotSupported() : void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The connection must implement shards configuration.');

        $connection = $this->createMock(Connection::class);

        $helper = $this->createMock(ConnectionHelper::class);
        $helper->expects(self::once())->method('getConnection')->willReturn($connection);

        $helperSet = new HelperSet();
        $helperSet->set($helper, 'connection');

        $dir = getcwd()?: '.';
        chdir(__DIR__);
        try {
            self::assertSame($connection, $this->connectionLoader->getConnection(null, 'shardId', $helperSet));
        } finally {
            chdir($dir);
        }
    }

    public function testGetShardedConnection() : void
    {
        $connection = $this->createMock(PoolingShardConnection::class);
        $connection->expects(self::once())->method('connect')->with('shardId');

        $helper = $this->createMock(ConnectionHelper::class);
        $helper->expects(self::once())->method('getConnection')->willReturn($connection);

        $helperSet = new HelperSet();
        $helperSet->set($helper, 'connection');

        $dir = getcwd()?: '.';
        chdir(__DIR__);
        try {
            self::assertSame($connection, $this->connectionLoader->getConnection(null, 'shardId', $helperSet));
        } finally {
            chdir($dir);
        }
    }

    protected function setUp() : void
    {
        $this->connectionLoader = new ConnectionLoader();
    }
}
