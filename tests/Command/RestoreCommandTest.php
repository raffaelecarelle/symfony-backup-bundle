<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Command\RestoreCommand;
use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class RestoreCommandTest extends TestCase
{
    private $mockBackupManager;
    private $command;
    private $commandTester;
    private $sampleBackup;

    protected function setUp(): void
    {
        $this->mockBackupManager = $this->createMock(BackupManager::class);

        $this->command = new RestoreCommand($this->mockBackupManager);

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);

        // Create a sample backup for testing
        $this->sampleBackup = [
            'id' => 'backup123',
            'type' => 'database',
            'name' => 'daily_db_backup',
            'file_path' => '/path/to/backup.sql.gz',
            'file_size' => 1048576, // 1 MB
            'created_at' => new \DateTimeImmutable('2023-01-01 10:00:00'),
            'storage' => 'local',
        ];
    }

    public function testExecuteSuccessfulRestore(): void
    {
        // Configure the mock to return the sample backup and successful restore
        $this->mockBackupManager->method('getBackup')->with('backup123')->willReturn($this->sampleBackup);
        $this->mockBackupManager->method('restore')->with('backup123', [])->willReturn(true);

        // Execute the command with force option to skip confirmation
        $this->commandTester->execute([
            'backup-id' => 'backup123',
            '--force' => true,
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check title and backup details
        $this->assertStringContainsString('Restore Backup', $output);
        $this->assertStringContainsString('backup123', $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('daily_db_backup', $output);
        $this->assertStringContainsString('/path/to/backup.sql.gz', $output);
        $this->assertStringContainsString('1 MB', $output);
        $this->assertStringContainsString('2023-01-01 10:00:00', $output);
        $this->assertStringContainsString('local', $output);

        // Check success message
        $this->assertStringContainsString('Backup restored successfully', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteFailedRestore(): void
    {
        // Configure the mock to return the sample backup but failed restore
        $this->mockBackupManager->method('getBackup')->with('backup123')->willReturn($this->sampleBackup);
        $this->mockBackupManager->method('restore')->with('backup123', [])->willReturn(false);

        // Execute the command with force option to skip confirmation
        $this->commandTester->execute([
            'backup-id' => 'backup123',
            '--force' => true,
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check error message
        $this->assertStringContainsString('Restore failed', $output);

        // Verify the exit code
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithException(): void
    {
        // Configure the mock to return the sample backup but throw an exception during restore
        $this->mockBackupManager->method('getBackup')->with('backup123')->willReturn($this->sampleBackup);
        $this->mockBackupManager->method('restore')->willThrowException(new \Exception('Unexpected error occurred'));

        // Execute the command with force option to skip confirmation
        $this->commandTester->execute([
            'backup-id' => 'backup123',
            '--force' => true,
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check error message
        $this->assertStringContainsString('Restore failed with exception', $output);
        $this->assertStringContainsString('Unexpected error occurred', $output);

        // Verify the exit code
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithBackupNotFound(): void
    {
        // Configure the mock to return null (backup not found)
        $this->mockBackupManager->method('getBackup')->with('nonexistent')->willReturn(null);

        // Execute the command
        $this->commandTester->execute([
            'backup-id' => 'nonexistent',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check error message
        $this->assertStringContainsString('Backup with ID "nonexistent" not found', $output);

        // Verify the exit code
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithCustomOptions(): void
    {
        // Configure the mock to return the sample backup and successful restore
        $this->mockBackupManager->method('getBackup')->with('backup123')->willReturn($this->sampleBackup);

        // Capture the options passed to restore
        $capturedOptions = null;
        $this->mockBackupManager->method('restore')
            ->willReturnCallback(function ($backupId, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return true;
            });

        // Execute the command with custom options
        $this->commandTester->execute([
            'backup-id' => 'backup123',
            '--force' => true,
            '--single-user' => true,
            '--recovery' => 'norecovery',
            '--backup-existing' => true,
        ]);

        // Verify the options were passed correctly
        $this->assertTrue($capturedOptions['single_user']);
        $this->assertEquals('norecovery', $capturedOptions['recovery']);
        $this->assertTrue($capturedOptions['backup_existing']);

        // Verify the output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup restored successfully', $output);
    }

    public function testExecuteWithConfirmationYes(): void
    {
        // Configure the mock to return the sample backup and successful restore
        $this->mockBackupManager->method('getBackup')->with('backup123')->willReturn($this->sampleBackup);
        $this->mockBackupManager->method('restore')->willReturn(true);

        // Execute the command with interactive input (yes to confirmation)
        $this->commandTester->setInputs(['y']);
        $this->commandTester->execute([
            'backup-id' => 'backup123',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check confirmation question and success message
        $this->assertStringContainsString('Are you sure you want to restore this backup?', $output);
        $this->assertStringContainsString('Backup restored successfully', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithConfirmationNo(): void
    {
        // Configure the mock to return the sample backup
        $this->mockBackupManager->method('getBackup')->with('backup123')->willReturn($this->sampleBackup);

        // The restore method should not be called
        $this->mockBackupManager->expects($this->never())->method('restore');

        // Execute the command with interactive input (no to confirmation)
        $this->commandTester->setInputs(['n']);
        $this->commandTester->execute([
            'backup-id' => 'backup123',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check confirmation question and cancellation message
        $this->assertStringContainsString('Are you sure you want to restore this backup?', $output);
        $this->assertStringContainsString('Restore cancelled', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testFormatFileSize(): void
    {
        $reflectionClass = new \ReflectionClass(RestoreCommand::class);
        $method = $reflectionClass->getMethod('formatFileSize');
        $method->setAccessible(true);

        // Test various file sizes
        $this->assertEquals('0 B', $method->invoke($this->command, 0));
        $this->assertEquals('100 B', $method->invoke($this->command, 100));
        $this->assertEquals('1 KB', $method->invoke($this->command, 1024));
        $this->assertEquals('1.5 KB', $method->invoke($this->command, 1536));
        $this->assertEquals('1 MB', $method->invoke($this->command, 1048576));
        $this->assertEquals('1 GB', $method->invoke($this->command, 1073741824));
        $this->assertEquals('1 TB', $method->invoke($this->command, 1099511627776));
    }
}
