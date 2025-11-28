<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Command;

use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Command\BackupCommand;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Symfony\Component\Console\Tester\CommandTester;

class BackupCommandTest extends TestCase
{
    private MockObject $mockBackupManager;

    private BackupCommand $command;

    private CommandTester $commandTester;

    private array $defaultConfig;

    protected function setUp(): void
    {
        $this->mockBackupManager = $this->createMock(BackupManager::class);

        $this->defaultConfig = [
            'database' => [
                'compression' => 'gzip',
            ],
            'filesystem' => [
                'compression' => 'zip',
                'paths' => [
                    ['path' => '/var/www/html', 'exclude' => ['cache', 'logs']],
                ],
            ],
        ];

        $this->command = new BackupCommand($this->mockBackupManager, $this->defaultConfig);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteSuccessfulDatabaseBackup(): void
    {
        // Create a successful backup result
        $backupResult = new BackupResult(
            true,
            '/path/to/backup.sql.gz',
            1024,
            new \DateTimeImmutable(),
            1.5
        );

        // Configure the mock to return the successful result
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturn($backupResult);

        // Execute the command
        $this->commandTester->execute([]);

        // Verify the output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup created successfully', $output);
        $this->assertStringContainsString('/path/to/backup.sql.gz', $output);
        $this->assertStringContainsString('1 KB', $output);
        $this->assertStringContainsString('1.50 seconds', $output);

        // Verify the exit code
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteFailedBackup(): void
    {
        // Create a failed backup result
        $backupResult = new BackupResult(
            false,
            null,
            null,
            new \DateTimeImmutable(),
            0.5,
            'Database connection failed'
        );

        // Configure the mock to return the failed result
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturn($backupResult);

        // Execute the command
        $this->commandTester->execute([]);

        // Verify the output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup failed', $output);
        $this->assertStringContainsString('Database connection failed', $output);

        // Verify the exit code
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithException(): void
    {
        // Configure the mock to throw an exception
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willThrowException(new \Exception('Unexpected error occurred'));

        // Execute the command
        $this->commandTester->execute([]);

        // Verify the output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup failed with exception', $output);
        $this->assertStringContainsString('Unexpected error occurred', $output);

        // Verify the exit code
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithCustomOptions(): void
    {
        // Create a successful backup result
        $backupResult = new BackupResult(
            true,
            '/path/to/custom_backup.tar.gz',
            2048,
            new \DateTimeImmutable(),
            2.5
        );

        // Configure the mock to return the successful result and capture the configuration
        $capturedConfig = null;
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturnCallback(function (BackupConfiguration $config) use (&$capturedConfig, $backupResult): BackupResult {
                $capturedConfig = $config;

                return $backupResult;
            });

        // Execute the command with custom options
        $this->commandTester->execute([
            '--type' => 'filesystem',
            '--name' => 'custom_backup',
            '--storage' => 's3',
            '--compression' => 'gzip',
            '--output-path' => '/custom/path',
            '--exclude' => ['cache', 'logs'],
        ]);

        // Verify the configuration was set correctly
        $this->assertEquals('filesystem', $capturedConfig->getType());
        $this->assertEquals('custom_backup', $capturedConfig->getName());
        $this->assertEquals('s3', $capturedConfig->getStorage());
        $this->assertEquals('gzip', $capturedConfig->getCompression());
        $this->assertEquals('/custom/path', $capturedConfig->getOutputPath());
        $this->assertEquals(['cache', 'logs'], $capturedConfig->getExclusions());

        // Verify the output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup created successfully', $output);
        $this->assertStringContainsString('/path/to/custom_backup.tar.gz', $output);
        $this->assertStringContainsString('2 KB', $output);
        $this->assertStringContainsString('2.50 seconds', $output);
    }

    public function testExecuteWithDefaultCompression(): void
    {
        // Create a successful backup result
        $backupResult = new BackupResult(
            true,
            '/path/to/backup.sql.gz',
            1024,
            new \DateTimeImmutable(),
            1.5
        );

        // Configure the mock to return the successful result and capture the configuration
        $capturedConfig = null;
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturnCallback(function (BackupConfiguration $config) use (&$capturedConfig, $backupResult): BackupResult {
                $capturedConfig = $config;

                return $backupResult;
            });

        // Execute the command without specifying compression
        $this->commandTester->execute([
            '--type' => 'database',
        ]);

        // Verify the default compression from config was used
        $this->assertEquals('gzip', $capturedConfig->getCompression());

        // Execute the command for filesystem backup
        $capturedConfig = null;

        // Reconfigure the mock for the second test
        $this->mockBackupManager = $this->createMock(BackupManager::class);
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturnCallback(function (BackupConfiguration $config) use (&$capturedConfig, $backupResult): BackupResult {
                $capturedConfig = $config;

                return $backupResult;
            });

        // Recreate the command with the updated mock
        $this->command = new BackupCommand(
            $this->mockBackupManager,
            [
                'database' => ['compression' => 'gzip'],
                'filesystem' => [
                    'compression' => 'zip',
                    'paths' => [
                        [
                            'path' => '/var/www/html',
                            'exclude' => ['cache', 'logs'],
                        ],
                    ],
                ],
            ]
        );

        $this->commandTester = new CommandTester($this->command);

        $this->commandTester->execute([
            '--type' => 'filesystem',
        ]);

        // Verify the default compression from config was used
        $this->assertEquals('zip', $capturedConfig->getCompression());
    }

    public function testExecuteWithFilesystemPaths(): void
    {
        // Create a successful backup result
        $backupResult = new BackupResult(
            true,
            '/path/to/backup.zip',
            1024,
            new \DateTimeImmutable(),
            1.5
        );

        // Configure the mock to return the successful result and capture the configuration
        $capturedConfig = null;
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturnCallback(function (BackupConfiguration $config) use (&$capturedConfig, $backupResult): BackupResult {
                $capturedConfig = $config;

                return $backupResult;
            });

        // Execute the command for filesystem backup without specifying paths
        $this->commandTester->execute([
            '--type' => 'filesystem',
        ]);

        // Verify the default paths from config were used
        $paths = $capturedConfig->getOption('paths');
        $this->assertIsArray($paths);
        $this->assertCount(1, $paths);
        $this->assertEquals('/var/www/html', $paths[0]['path']);
        $this->assertEquals(['cache', 'logs'], $paths[0]['exclude']);
    }

    public function testFormatFileSize(): void
    {
        $reflectionClass = new \ReflectionClass(BackupCommand::class);
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
