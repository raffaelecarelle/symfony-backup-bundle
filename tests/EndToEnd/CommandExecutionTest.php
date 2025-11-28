<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end test for command execution using the TestApp kernel.
 */
class CommandExecutionTest extends AbstractEndToEndTest
{
    private ?string $backupId = null;

    protected function setupTest(): void
    {
        // Seed the SQLite database used by Doctrine default connection in TestApp
        // The sqlite connection is configured at tests/_fixtures/TestApp/config/packages/doctrine.yaml
        $projectDir = \dirname(__DIR__) . '/_fixtures/TestApp';
        $sqlitePath = $projectDir . '/var/test.sqlite';
        if (!is_dir(\dirname($sqlitePath))) {
            mkdir(\dirname($sqlitePath), 0777, true);
        }

        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS users');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');

        $stmt = $pdo->prepare('INSERT INTO users (id, name, email) VALUES (?, ?, ?)');
        $stmt->execute([1, 'John Doe', 'john@example.com']);
        $stmt->execute([2, 'Jane Smith', 'jane@example.com']);

        // Ensure DB file exists and is readable for the SQLite adapter
        $this->assertFileExists($sqlitePath, 'SQLite DB file must exist for backup');
        $this->assertTrue(is_readable($sqlitePath), 'SQLite DB file must be readable');

        // Ensure backup directory exists to avoid I/O issues
        $backupsDir = $projectDir . '/var/backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0777, true);
        }
    }

    public function testBackupCommand(): void
    {
        $command = $this->application->find('pro:backup:create');
        $tester = new CommandTester($command);

        $tester->execute([
            '--type' => 'database',
            '--name' => 'command_backup_test',
            // rely on default connection from config and gzip compression
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Backup created successfully', $output);
        // The console may wrap long lines; allow any whitespace between 'File:' and the path
        $this->assertMatchesRegularExpression('/File:\s+.*\\.sqlite(\\.gz)?/ms', $output);
        $this->assertEquals(0, $tester->getStatusCode());
    }

    public function testListCommand(): void
    {
        // Ensure a backup exists
        $this->testBackupCommand();

        $command = $this->application->find('pro:backup:list');
        $tester = new CommandTester($command);
        $tester->execute([
            '--type' => 'database',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('command_backup_test', $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('gzip', $output);

        // Extract backup ID (the ListCommand prints rows with ID first column)
        if (preg_match('/^\s*(backup_[a-f0-9._-]+)\s+database/m', $output, $m)) {
            $this->backupId = $m[1];
        }

        $this->assertNotNull($this->backupId, 'Should find a backup ID');
    }

    public function testRestoreCommand(): void
    {
        if (null === $this->backupId) {
            $this->testListCommand();
        }

        $this->assertNotNull($this->backupId, 'Backup ID should be set');

        $command = $this->application->find('pro:backup:restore');
        $tester = new CommandTester($command);

        $tester->execute([
            'backup-id' => $this->backupId,
            '--force' => true,
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Backup restored successfully', $output);
        $this->assertEquals(0, $tester->getStatusCode());

        // Verify restored database content by reading the sqlite used by the app
        $projectDir = \dirname(__DIR__) . '/_fixtures/TestApp';
        $sqlitePath = $projectDir . '/var/test.sqlite';
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $this->assertEquals(2, (int) $stmt->fetchColumn(), 'Should have 2 users');
    }
}
