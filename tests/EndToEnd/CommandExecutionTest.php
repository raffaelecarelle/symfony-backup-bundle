<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Command\BackupCommand;
use ProBackupBundle\Command\ListCommand;
use ProBackupBundle\Command\RestoreCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end test for command execution.
 *
 * This test verifies that the commands work correctly with a real BackupManager
 * instead of mocks.
 */
class CommandExecutionTest extends AbstractEndToEndTest
{
    private string $dbPath;
    private Application $application;

    protected function setupTest(): void
    {
        // Create a test SQLite database
        $this->dbPath = $this->createTempSQLiteDatabase('command_test.db', [
            'users' => [
                'id' => 'INTEGER PRIMARY KEY',
                'name' => 'TEXT',
                'email' => 'TEXT',
            ],
        ]);

        // Insert some test data
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO users (id, name, email) VALUES
            (1, 'John Doe', 'john@example.com'),
            (2, 'Jane Smith', 'jane@example.com')");

        // Create Symfony application with commands
        $this->application = new Application();

        // Create configuration for commands
        $config = [
            'database' => [
                'compression' => 'gzip',
            ],
            'filesystem' => [
                'compression' => 'zip',
                'paths' => [
                    ['path' => $this->tempDir, 'exclude' => ['command_test.db']],
                ],
            ],
        ];

        // Register commands
        $this->application->add(new BackupCommand($this->backupManager, $config));
        $this->application->add(new ListCommand($this->backupManager));
        $this->application->add(new RestoreCommand($this->backupManager));
    }

    // Store backup ID for use in subsequent tests
    private ?string $backupId = null;

    public function testBackupCommand(): void
    {
        $command = $this->application->find('backup:create');
        $commandTester = new CommandTester($command);

        // Execute the command with SQLite database connection
        $commandTester->execute([
            '--type' => 'database',
            '--name' => 'command_backup_test',
        ]);

        // Verify the output
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Backup created successfully', $output);
        $this->assertStringContainsString('.sql.gz', $output);

        // Verify the exit code
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testListCommand(): void
    {
        // First run the backup command to ensure we have a backup
        $this->testBackupCommand();

        $command = $this->application->find('backup:list');
        $commandTester = new CommandTester($command);

        // Execute the list command
        $commandTester->execute([
            '--type' => 'database',
        ]);

        // Verify the output
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('command_backup_test', $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('gzip', $output);

        // Extract backup ID from the output and store it for later tests
        preg_match('/\| ([a-f0-9]+) \|/', $output, $matches);
        $this->assertCount(2, $matches, 'Should find a backup ID');
        $this->backupId = $matches[1];
    }

    public function testRestoreCommand(): void
    {
        // First run the list command to ensure we have a backup ID
        if (null === $this->backupId) {
            $this->testListCommand();
        }

        $this->assertNotNull($this->backupId, 'Backup ID should be set');

        $command = $this->application->find('backup:restore');
        $commandTester = new CommandTester($command);

        $restoreDbPath = $this->tempDir.'/restored_command.db';

        // Execute the restore command
        $commandTester->execute([
            'backup-id' => $this->backupId,
        ]);

        // Verify the output
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Backup restored successfully', $output);

        // Verify the exit code
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify the restored database
        $this->assertTrue($this->filesystem->exists($restoreDbPath), 'Restored database file should exist');

        // Verify restored data
        $pdo = new \PDO('sqlite:'.$restoreDbPath);
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $this->assertEquals(2, $stmt->fetchColumn(), 'Should have 2 users');
    }
}
