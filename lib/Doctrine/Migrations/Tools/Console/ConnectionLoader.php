<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tools\Console;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\Loader\ArrayConnectionConfigurationLoader;
use Doctrine\Migrations\Configuration\Connection\Loader\ConnectionHelperLoader;
use Doctrine\Migrations\Configuration\Connection\Loader\NoConnectionLoader;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The ConnectionLoader class is responsible for loading the Doctrine\DBAL\Connection instance to use for migrations.
 *
 * @internal
 */
class ConnectionLoader
{
    public function getConnection(
        InputInterface $input,
        HelperSet $helperSet
    ) : Connection {

        $loader = new ArrayConnectionConfigurationLoader(
            $input->getOption('db-configuration'),
            new ArrayConnectionConfigurationLoader(
                'migrations-db.php',
                new ConnectionHelperLoader($helperSet, 'connection', new NoConnectionLoader())
            )
        );

        return $loader->getConnection();
    }
}
