<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tests\Tools\Console\Command;

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Generator\DiffGenerator;
use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DiffCommandTest extends TestCase
{
    /** @var DiffGenerator|MockObject */
    private $migrationDiffGenerator;

    /** @var Configuration|MockObject */
    private $configuration;

    /** @var DiffCommand|MockObject */
    private $diffCommand;

    public function testExecute() : void
    {
        $input  = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $this->configuration->expects(self::once())
            ->method('generateVersionNumber')
            ->willReturn('1234');

        $input->expects(self::at(0))
            ->method('getOption')
            ->with('filter-expression')
            ->willReturn('filter expression');

        $input->expects(self::at(1))
            ->method('getOption')
            ->with('formatted')
            ->willReturn(true);

        $input->expects(self::at(2))
            ->method('getOption')
            ->with('line-length')
            ->willReturn(80);

        $input->expects(self::at(3))
            ->method('getOption')
            ->with('check-database-platform')
            ->willReturn(true);

        $input->expects(self::at(4))
            ->method('getOption')
            ->with('editor-cmd')
            ->willReturn('mate');

        $this->configuration->expects(self::once())
            ->method('generateVersionNumber')
            ->willReturn('1234');

        $this->migrationDiffGenerator->expects(self::once())
            ->method('generate')
            ->with('1234', 'filter expression', true, 80)
            ->willReturn('/path/to/migration.php');

        $this->diffCommand->expects(self::once())
            ->method('procOpen')
            ->with('mate', '/path/to/migration.php');

        $output->expects(self::once())
            ->method('writeln')
            ->with([
                'Generated new migration class to "<info>/path/to/migration.php</info>"',
                '',
                'To run just this migration for testing purposes, you can use <info>migrations:execute --up 1234</info>',
                '',
                'To revert the migration you can use <info>migrations:execute --down 1234</info>',
            ]);

        $this->diffCommand->execute($input, $output);
    }

    protected function setUp() : void
    {
        $this->migrationDiffGenerator = $this->createMock(DiffGenerator::class);
        $this->configuration          = $this->createMock(Configuration::class);

        $this->diffCommand = $this->getMockBuilder(DiffCommand::class)
            ->setMethods(['createMigrationDiffGenerator', 'procOpen'])
            ->getMock();

        $this->diffCommand->setMigrationConfiguration($this->configuration);

        $this->diffCommand->expects(self::once())
            ->method('createMigrationDiffGenerator')
            ->willReturn($this->migrationDiffGenerator);
    }
}
