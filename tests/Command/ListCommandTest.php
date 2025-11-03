<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Command\ListCommand;
use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ListCommandTest extends TestCase
{
    private $mockBackupManager;
    private $command;
    private $commandTester;
    private $sampleBackups;
    private $storageUsage;

    protected function setUp(): void
    {
        $this->mockBackupManager = $this->createMock(BackupManager::class);

        $this->command = new ListCommand($this->mockBackupManager);

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);

        // Create sample backups for testing
        $this->sampleBackups = [
            [
                'id' => 'backup1',
                'type' => 'database',
                'name' => 'daily_db_backup',
                'file_size' => 1048576, // 1 MB
                'created_at' => new \DateTimeImmutable('2023-01-01 10:00:00'),
                'storage' => 'local',
            ],
            [
                'id' => 'backup2',
                'type' => 'filesystem',
                'name' => 'weekly_fs_backup',
                'file_size' => 10485760, // 10 MB
                'created_at' => new \DateTimeImmutable('2023-01-02 11:00:00'),
                'storage' => 's3',
            ],
            [
                'id' => 'backup3',
                'type' => 'database',
                'name' => 'monthly_db_backup',
                'file_size' => 5242880, // 5 MB
                'created_at' => new \DateTimeImmutable('2023-01-03 12:00:00'),
                'storage' => 'local',
            ],
        ];

        // Create sample storage usage
        $this->storageUsage = [
            'total' => 16777216, // 16 MB
            'by_type' => [
                'database' => 6291456, // 6 MB
                'filesystem' => 10485760, // 10 MB
            ],
        ];
    }

    public function testExecuteWithTableFormat(): void
    {
        // Configure the mock to return sample backups and storage usage
        $this->mockBackupManager->method('listBackups')->willReturn($this->sampleBackups);
        $this->mockBackupManager->method('getStorageUsage')->willReturn($this->storageUsage);

        // Execute the command with default format (table)
        $this->commandTester->execute([]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check title
        $this->assertStringContainsString('Available Backups: 3', $output);

        // Check table headers
        $this->assertStringContainsString('ID', $output);
        $this->assertStringContainsString('Type', $output);
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('Size', $output);
        $this->assertStringContainsString('Created', $output);
        $this->assertStringContainsString('Storage', $output);

        // Check backup data
        $this->assertStringContainsString('backup1', $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('daily_db_backup', $output);
        $this->assertStringContainsString('1 MB', $output);
        $this->assertStringContainsString('2023-01-01 10:00:00', $output);
        $this->assertStringContainsString('local', $output);

        // Check storage usage
        $this->assertStringContainsString('Storage Usage', $output);
        $this->assertStringContainsString('Total', $output);
        $this->assertStringContainsString('16 MB', $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('6 MB', $output);
        $this->assertStringContainsString('filesystem', $output);
        $this->assertStringContainsString('10 MB', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithJsonFormat(): void
    {
        // Configure the mock to return sample backups and storage usage
        $this->mockBackupManager->method('listBackups')->willReturn($this->sampleBackups);
        $this->mockBackupManager->method('getStorageUsage')->willReturn($this->storageUsage);

        // Execute the command with JSON format
        $this->commandTester->execute([
            '--format' => 'json',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check that the output is valid JSON
        $jsonData = json_decode((string) $output, true);
        $this->assertIsArray($jsonData);
        $this->assertCount(3, $jsonData);

        // Check the first backup in the JSON output (should be backup3 due to newest-first sorting)
        $this->assertEquals('backup3', $jsonData[0]['id']);
        $this->assertEquals('database', $jsonData[0]['type']);
        $this->assertEquals('monthly_db_backup', $jsonData[0]['name']);
        $this->assertEquals(5242880, $jsonData[0]['file_size']);
        $this->assertEquals('2023-01-03 12:00:00', $jsonData[0]['created_at']);
        $this->assertEquals('local', $jsonData[0]['storage']);

        // Check the second backup (should be backup2)
        $this->assertEquals('backup2', $jsonData[1]['id']);
        $this->assertEquals('filesystem', $jsonData[1]['type']);
        $this->assertEquals('weekly_fs_backup', $jsonData[1]['name']);
        $this->assertEquals(10485760, $jsonData[1]['file_size']);
        $this->assertEquals('2023-01-02 11:00:00', $jsonData[1]['created_at']);
        $this->assertEquals('s3', $jsonData[1]['storage']);

        // Check the third backup (should be backup1)
        $this->assertEquals('backup1', $jsonData[2]['id']);
        $this->assertEquals('database', $jsonData[2]['type']);
        $this->assertEquals('daily_db_backup', $jsonData[2]['name']);
        $this->assertEquals(1048576, $jsonData[2]['file_size']);
        $this->assertEquals('2023-01-01 10:00:00', $jsonData[2]['created_at']);
        $this->assertEquals('local', $jsonData[2]['storage']);

        // Storage usage should not be displayed in JSON format
        $this->assertStringNotContainsString('Storage Usage', $output);
        $this->assertStringNotContainsString('Total', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithCsvFormat(): void
    {
        // Configure the mock to return sample backups and storage usage
        $this->mockBackupManager->method('listBackups')->willReturn($this->sampleBackups);
        $this->mockBackupManager->method('getStorageUsage')->willReturn($this->storageUsage);

        // Execute the command with CSV format
        $this->commandTester->execute([
            '--format' => 'csv',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check CSV header
        $this->assertStringContainsString('ID,Type,Name,Size,Created,Storage', $output);

        // Check CSV data (sorted by newest first)
        $this->assertStringContainsString('backup3,database,monthly_db_backup,5 MB,2023-01-03 12:00:00,local', $output);
        $this->assertStringContainsString('backup2,filesystem,weekly_fs_backup,10 MB,2023-01-02 11:00:00,s3', $output);
        $this->assertStringContainsString('backup1,database,daily_db_backup,1 MB,2023-01-01 10:00:00,local', $output);

        // Check that storage usage is still displayed as a table
        $this->assertStringContainsString('Storage Usage', $output);
        $this->assertStringContainsString('Total', $output);
        $this->assertStringContainsString('16 MB', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithTypeFilter(): void
    {
        // Configure the mock to return sample backups and storage usage
        $this->mockBackupManager->method('listBackups')->willReturn($this->sampleBackups);
        $this->mockBackupManager->method('getStorageUsage')->willReturn($this->storageUsage);

        // Execute the command with type filter
        $this->commandTester->execute([
            '--type' => 'database',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check that only database backups are displayed
        $this->assertStringContainsString('Available Backups: 2', $output);
        $this->assertStringContainsString('backup1', $output);
        $this->assertStringContainsString('backup3', $output);
        $this->assertStringNotContainsString('backup2', $output);
        $this->assertStringNotContainsString('weekly_fs_backup', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithStorageFilter(): void
    {
        // Configure the mock to return sample backups and storage usage
        $this->mockBackupManager->method('listBackups')->willReturn($this->sampleBackups);
        $this->mockBackupManager->method('getStorageUsage')->willReturn($this->storageUsage);

        // Execute the command with storage filter
        $this->commandTester->execute([
            '--storage' => 's3',
        ]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check that only s3 backups are displayed
        $this->assertStringContainsString('Available Backups: 1', $output);
        $this->assertStringContainsString('backup2', $output);
        $this->assertStringContainsString('weekly_fs_backup', $output);
        $this->assertStringNotContainsString('backup1', $output);
        $this->assertStringNotContainsString('backup3', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithNoBackups(): void
    {
        // Configure the mock to return empty backups
        $this->mockBackupManager->method('listBackups')->willReturn([]);
        $this->mockBackupManager->method('getStorageUsage')->willReturn([
            'total' => 0,
            'by_type' => [],
        ]);

        // Execute the command
        $this->commandTester->execute([]);

        // Verify the output
        $output = $this->commandTester->getDisplay();

        // Check warning message
        $this->assertStringContainsString('No backups found', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testFormatFileSize(): void
    {
        $reflectionClass = new \ReflectionClass(ListCommand::class);
        $method = $reflectionClass->getMethod('formatFileSize');

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
