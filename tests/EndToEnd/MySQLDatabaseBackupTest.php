<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * End-to-end test for MySQL database backup functionality.
 */
class MySQLDatabaseBackupTest extends AbstractEndToEndTest
{
    private string $testDatabase;

    private string $host;

    private string $port;

    private string $user;

    private string $password;

    protected function setupTest(): void
    {
        // Read connection params from env (CI uses 127.0.0.1; local Docker uses service name)
        $this->host = getenv('MYSQL_HOST') ?: 'mysql';
        $this->port = (string) (getenv('MYSQL_PORT') ?: '3306');
        $this->user = getenv('MYSQL_USER') ?: 'root';
        $this->password = getenv('MYSQL_PASSWORD') ?: 'root';
        $this->testDatabase = getenv('MYSQL_DATABASE') ?: 'test_db';

        // Skip test if MySQL is not available
        if (!$this->isMySQLAvailable()) {
            $this->markTestSkipped('MySQL is not available');
        }

        // Prepare schema and seed data on target database
        $this->prepareMySQLSchema();
        $this->seedMySQLTestData();
    }

    private function isMySQLAvailable(): bool
    {
        try {
            $dsn = \sprintf('mysql:host=%s;port=%d;dbname=information_schema', $this->host, $this->port);
            $pdo = new \PDO($dsn, $this->user, $this->password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\Throwable) {
            // echo instead of dump to avoid noisy output in CI
            // echo 'MySQL availability check failed: '.$e->getMessage()."\n";
            return false;
        }
    }

    private function prepareMySQLSchema(): void
    {
        // Ensure target database exists first
        $serverDsn = \sprintf('mysql:host=%s;port=%d;dbname=information_schema', $this->host, (int) $this->port);
        $serverPdo = new \PDO($serverDsn, $this->user, $this->password);
        $serverPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbName = str_replace('`', '', $this->testDatabase);
        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Now connect to the target database and prepare schema
        $dsn = \sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, (int) $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Drop tables if they exist to ensure a clean state
        $pdo->exec('DROP TABLE IF EXISTS posts');
        $pdo->exec('DROP TABLE IF EXISTS users');

        // Create users table
        $pdo->exec('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        // Create posts table
        $pdo->exec('
            CREATE TABLE posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function seedMySQLTestData(): void
    {
        $dsn = \sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Insert test users
        $pdo->exec("
            INSERT INTO users (name, email) VALUES
            ('John Doe', 'john@example.com'),
            ('Jane Smith', 'jane@example.com'),
            ('Bob Johnson', 'bob@example.com')
        ");

        // Insert test posts
        $pdo->exec("
            INSERT INTO posts (user_id, title, content) VALUES
            (1, 'First Post', 'This is the first post content'),
            (1, 'Second Post', 'This is the second post content'),
            (2, 'Hello World', 'Hello world from Jane'),
            (3, 'MySQL Backup Test', 'Testing MySQL backup functionality')
        ");
    }

    public function testMySQLBackupWithGzipCompression(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setConnectionName('mysql');
        $config->setCompression('gzip');
        $config->setName('mysql_backup_test');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: ' . $result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');
        $this->assertNotNull($result->getFileSize(), 'Backup size should not be null');
        $this->assertGreaterThan(0, $result->getFileSize(), 'Backup size should be greater than 0');

        // Verify the backup file exists
        $this->assertTrue($this->filesystem->exists($result->getFilePath()), 'Backup file should exist');

        // Verify it's a gzip file
        $this->assertStringEndsWith('.gz', $result->getFilePath(), 'Backup should be gzip compressed');
    }

    public function testMySQLBackupWithZipCompression(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setConnectionName('mysql');
        $config->setCompression('zip');
        $config->setName('mysql_backup_zip_test');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: ' . $result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');

        // Verify it's a zip file
        $this->assertStringEndsWith('.zip', $result->getFilePath(), 'Backup should be zip compressed');
    }

    public function testMySQLBackupAndRestore(): void
    {
        // Create backup
        $config = new BackupConfiguration('database');
        $config->setConnectionName('mysql');
        $config->setCompression('gzip');
        $config->setName('mysql_restore_test');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Modify the database
        $pdo = new \PDO(\sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, (int) $this->port, $this->testDatabase), $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DELETE FROM posts');
        $pdo->exec('DELETE FROM users');

        // Verify data is gone
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(0, $row['count'], 'Users table should be empty');
        $pdo = null;

        // Restore from backup
        $restoreResult = $this->backupManager->restore($result->getId(), [
            'connection_name' => 'mysql',
        ]);

        $this->assertTrue($restoreResult, 'Restore should be successful');

        // Verify data is restored
        $pdo = new \PDO(\sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, (int) $this->port, $this->testDatabase), $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Check users
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(3, $row['count'], 'Should have 3 users after restore');

        // Check posts
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM posts');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(4, $row['count'], 'Should have 4 posts after restore');

        // Check specific data
        $stmt = $pdo->query("SELECT name FROM users WHERE email = 'john@example.com'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('John Doe', $row['name'], 'User data should match');

        $stmt = $pdo->query('SELECT title FROM posts WHERE user_id = 3');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('MySQL Backup Test', $row['title'], 'Post data should match');
    }

    public function testMySQLBackupWithExcludedTables(): void
    {
        $config = new BackupConfiguration('database');
        $config->setConnectionName('mysql');
        $config->setCompression('gzip');
        $config->setName('mysql_exclude_test');
        $config->setExclusions(['posts']); // Exclude posts table

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup with exclusions should be successful');
    }

    protected function tearDown(): void
    {
        // Cleanup test database
        if ($this->isMySQLAvailable()) {
            try {
                $pdo = new \PDO('mysql:host=mysql;port=3306;dbname=mysql', 'root', 'root');
                $pdo->exec("DROP DATABASE IF EXISTS `{$this->testDatabase}`");
                $pdo = null;
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }
}
