<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * End-to-end test for PostgreSQL database backup functionality.
 */
class PostgreSQLDatabaseBackupTest extends AbstractEndToEndTest
{
    private string $testDatabase = 'test_db';
    private string $host = 'postgres';
    private int $port = 5432;
    private string $user = 'postgres';
    private string $password = 'root';

    protected function setupTest(): void
    {
        // Skip test if PostgreSQL is not available
        if (!$this->isPostgreSQLAvailable()) {
            $this->markTestSkipped('PostgreSQL is not available');
        }

        // Create test database and tables
        $this->createPostgreSQLTestDatabase();
        $this->seedPostgreSQLTestData();
    }

    private function isPostgreSQLAvailable(): bool
    {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->host, $this->port);
            $pdo = new \PDO($dsn, $this->user, $this->password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createPostgreSQLTestDatabase(): void
    {
        // Connect to postgres database
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->host, $this->port);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Drop database if exists (terminate existing connections first)
        $pdo->exec("
            SELECT pg_terminate_backend(pg_stat_activity.pid)
            FROM pg_stat_activity
            WHERE pg_stat_activity.datname = '{$this->testDatabase}'
            AND pid <> pg_backend_pid()
        ");
        $pdo->exec("DROP DATABASE IF EXISTS {$this->testDatabase}");

        // Create database
        $pdo->exec("CREATE DATABASE {$this->testDatabase} WITH ENCODING 'UTF8'");

        // Connect to the new database
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create users table
        $pdo->exec("
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create posts table
        $pdo->exec("
            CREATE TABLE posts (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create index
        $pdo->exec("CREATE INDEX idx_posts_user_id ON posts(user_id)");
    }

    private function seedPostgreSQLTestData(): void
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->testDatabase);
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
            (3, 'PostgreSQL Backup Test', 'Testing PostgreSQL backup functionality')
        ");
    }

    public function testPostgreSQLBackupWithGzipCompression(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setConnectionName('pgsql');
        $config->setCompression('gzip');
        $config->setName('postgres_backup_test');
        $config->setOption('format', 'plain'); // Use plain format for pg_dump

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: '.$result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');
        $this->assertNotNull($result->getFileSize(), 'Backup size should not be null');
        $this->assertGreaterThan(0, $result->getFileSize(), 'Backup size should be greater than 0');

        // Verify the backup file exists
        $this->assertTrue($this->filesystem->exists($result->getFilePath()), 'Backup file should exist');

        // Verify it's a gzip file
        $this->assertStringEndsWith('.gz', $result->getFilePath(), 'Backup should be gzip compressed');
    }

    public function testPostgreSQLBackupWithZipCompression(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setConnectionName('pgsql');
        $config->setCompression('zip');
        $config->setName('postgres_backup_zip_test');
        $config->setOption('format', 'plain');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: '.$result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');

        // Verify it's a zip file
        $this->assertStringEndsWith('.zip', $result->getFilePath(), 'Backup should be zip compressed');
    }

    public function testPostgreSQLBackupWithCustomFormat(): void
    {
        // Create backup configuration with custom format
        $config = new BackupConfiguration('database');
        $config->setConnectionName('pgsql');
        $config->setCompression('gzip');
        $config->setName('postgres_custom_format_test');
        $config->setOption('format', 'custom'); // Use custom format

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: '.$result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');
    }

    public function testPostgreSQLBackupAndRestore(): void
    {
        // Create backup
        $config = new BackupConfiguration('database');
        $config->setConnectionName('pgsql');
        $config->setCompression('gzip');
        $config->setName('postgres_restore_test');
        $config->setOption('format', 'plain');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Modify the database
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('DELETE FROM posts');
        $pdo->exec('DELETE FROM users');

        // Verify data is gone
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(0, $row['count'], 'Users table should be empty');

        // Restore from backup
        $restoreResult = $this->backupManager->restore($result->getId(), [
            'connection_name' => 'postgres',
        ]);

        $this->assertTrue($restoreResult, 'Restore should be successful');

        // Verify data is restored
        $pdo = new \PDO($dsn, $this->user, $this->password);
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
        $this->assertEquals('PostgreSQL Backup Test', $row['title'], 'Post data should match');
    }

    public function testPostgreSQLBackupWithExcludedTables(): void
    {
        $config = new BackupConfiguration('database');
        $config->setConnectionName('pgsql');
        $config->setCompression('gzip');
        $config->setName('postgres_exclude_test');
        $config->setOption('format', 'plain');
        $config->setExclusions(['posts']); // Exclude posts table

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup with exclusions should be successful');
    }

    public function testPostgreSQLBackupSchemaOnly(): void
    {
        $config = new BackupConfiguration('database');
        $config->setConnectionName('pgsql');
        $config->setCompression('gzip');
        $config->setName('postgres_schema_only_test');
        $config->setOption('format', 'plain');
        $config->setOption('schema_only', true); // Backup schema only, no data

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Schema-only backup should be successful');
    }

    protected function tearDown(): void
    {
        // Cleanup test database
        if ($this->isPostgreSQLAvailable()) {
            try {
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->host, $this->port);
                $pdo = new \PDO($dsn, $this->user, $this->password);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // Terminate connections and drop database
                $pdo->exec("
                    SELECT pg_terminate_backend(pg_stat_activity.pid)
                    FROM pg_stat_activity
                    WHERE pg_stat_activity.datname = '{$this->testDatabase}'
                    AND pid <> pg_backend_pid()
                ");
                $pdo->exec("DROP DATABASE IF EXISTS {$this->testDatabase}");
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }
}
