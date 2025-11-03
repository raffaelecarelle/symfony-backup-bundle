<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * End-to-end test for SQLite database backup functionality.
 */
class SQLiteDatabaseBackupTest extends AbstractEndToEndTest
{
    private string $dbPath;

    protected function setupTest(): void
    {
        // Create a test SQLite database with some data
        $this->dbPath = $this->createTempSQLiteDatabase('test.db', [
            'users' => [
                'id' => 'INTEGER PRIMARY KEY',
                'name' => 'TEXT',
                'email' => 'TEXT',
            ],
            'posts' => [
                'id' => 'INTEGER PRIMARY KEY',
                'user_id' => 'INTEGER',
                'title' => 'TEXT',
                'content' => 'TEXT',
            ],
        ]);

        // Insert some test data
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO users (id, name, email) VALUES
            (1, 'John Doe', 'john@example.com'),
            (2, 'Jane Smith', 'jane@example.com')");

        $pdo->exec("INSERT INTO posts (id, user_id, title, content) VALUES
            (1, 1, 'First Post', 'This is the first post content'),
            (2, 1, 'Second Post', 'This is the second post content'),
            (3, 2, 'Hello World', 'Hello world content')");
    }

    public function testSQLiteBackup(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setCompression('gzip');
        $config->setName('sqlite_backup_test');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');
        $this->assertNotNull($result->getSize(), 'Backup size should not be null');
        $this->assertGreaterThan(0, $result->getSize(), 'Backup size should be greater than 0');

        // Verify the backup file exists
        $this->assertTrue($this->filesystem->exists($result->getFilePath()), 'Backup file should exist');

        // Test restore functionality
        $restoreDbPath = $this->tempDir.'/restored.db';
        $restoreResult = $this->backupManager->restore($result->getId(), [
            'connection' => [
                'driver' => 'sqlite',
                'path' => $restoreDbPath,
            ],
        ]);

        $this->assertTrue($restoreResult, 'Restore should be successful');
        $this->assertTrue($this->filesystem->exists($restoreDbPath), 'Restored database file should exist');

        // Verify restored data
        $pdo = new \PDO('sqlite:'.$restoreDbPath);

        // Check users table
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $this->assertEquals(2, $stmt->fetchColumn(), 'Should have 2 users');

        // Check posts table
        $stmt = $pdo->query('SELECT COUNT(*) FROM posts');
        $this->assertEquals(3, $stmt->fetchColumn(), 'Should have 3 posts');

        // Check specific data
        $stmt = $pdo->query("SELECT name FROM users WHERE email = 'john@example.com'");
        $this->assertEquals('John Doe', $stmt->fetchColumn(), 'User data should match');

        $stmt = $pdo->query('SELECT title FROM posts WHERE user_id = 2');
        $this->assertEquals('Hello World', $stmt->fetchColumn(), 'Post data should match');
    }

    public function testListBackups(): void
    {
        // Create a backup first
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setCompression('gzip');
        $config->setName('sqlite_list_test');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // List backups
        $backups = $this->backupManager->listBackups($config);

        // Assert we have at least one backup
        $this->assertNotEmpty($backups, 'Should have at least one backup');

        // Find our backup in the list
        $found = false;
        foreach ($backups as $backup) {
            if ($backup['file_path'] === $result->getFilePath()) {
                $found = true;
                $this->assertEquals('sqlite_list_test', $backup['name']);
                $this->assertEquals('database', $backup['type']);
                $this->assertEquals('gzip', $backup['metadata']['compression']);
                break;
            }
        }

        $this->assertTrue($found, 'Should find our backup in the list');
    }

    public function testDeleteBackup(): void
    {
        // Create a backup first
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setCompression('gzip');
        $config->setName('sqlite_delete_test');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Get the backup ID
        $backups = $this->backupManager->listBackups($config);
        $backupId = null;
        foreach ($backups as $backup) {
            if ($backup['file_path'] === $result->getFilePath()) {
                $backupId = $backup['id'];
                break;
            }
        }

        $this->assertNotNull($backupId, 'Should have a backup ID');

        // Delete the backup
        $deleteResult = $this->backupManager->deleteBackup($backupId);
        $this->assertTrue($deleteResult, 'Delete should be successful');

        // Verify the backup is gone
        $backups = $this->backupManager->listBackups($config);
        $found = false;
        foreach ($backups as $backup) {
            if ($backup['id'] === $backupId) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, 'Backup should be deleted');
        $this->assertFalse($this->filesystem->exists($result->getFilePath()), 'Backup file should be deleted');
    }
}
