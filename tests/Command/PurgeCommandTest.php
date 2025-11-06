<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Command\PurgeCommand;
use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeCommandTest extends TestCase
{
    public function testPurgeAllExecutesApplyRetentionPolicyForAll(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->expects(self::once())
            ->method('applyRetentionPolicy')
            ->with(null, false);

        $application = new Application();
        $application->add(new PurgeCommand($manager));

        $command = $application->find('pro:backup:purge');
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Retention policy applied', $tester->getDisplay());
    }

    public function testPurgeDatabaseDryRun(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->expects(self::once())
            ->method('applyRetentionPolicy')
            ->with('database', true);

        $application = new Application();
        $application->add(new PurgeCommand($manager));

        $command = $application->find('pro:backup:purge');
        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--type' => 'database',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exit);
    }

    public function testInvalidTypeReturnsInvalid(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->expects(self::never())->method('applyRetentionPolicy');

        $application = new Application();
        $application->add(new PurgeCommand($manager));

        $command = $application->find('pro:backup:purge');
        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--type' => 'invalid',
        ]);

        self::assertSame(2, $exit); // Command::INVALID
        self::assertStringContainsString('Invalid type', $tester->getDisplay());
    }
}
