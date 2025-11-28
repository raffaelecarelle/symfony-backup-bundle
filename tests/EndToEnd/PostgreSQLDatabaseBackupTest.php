<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * End-to-end test for PostgreSQL database backup functionality.
 */
class PostgreSQLDatabaseBackupTest extends AbstractEndToEndTest
{
    private string $dbName;

    private string $host;

    private string $port;

    private string $user;

    private string $password;

    protected function setupTest(): void
    {
        // Read connection params from env; CI exposes services on 127.0.0.1
        $this->host = getenv('PGHOST') ?: 'postgres';
        $this->port = (string) (getenv('PGPORT') ?: '5432');
        $this->user = getenv('PGUSER') ?: 'postgres';
        $this->password = getenv('PGPASSWORD') ?: 'root';
        $this->dbName = getenv('PGDATABASE') ?: 'test_db';

        // Skip test if PostgreSQL is not available
        if (!$this->isPostgreSQLAvailable()) {
            $this->markTestSkipped('PostgreSQL is not available');
        }

        // Ensure the target database exists before preparing schema/seed
        $this->ensurePostgreSQLDatabaseExists();

        // Create test schema and seed data in target database
        $this->preparePostgreSQLSchema();
        $this->seedPostgreSQLTestData();
    }

    private function isPostgreSQLAvailable(): bool
    {
        try {
            $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->host, (int) $this->port);
            $pdo = new \PDO($dsn, $this->user, $this->password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensurePostgreSQLDatabaseExists(): void
    {
        // Connect to maintenance DB to check/create target database
        $maintenanceDsn = \sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->host, (int) $this->port);
        $pdo = new \PDO($maintenanceDsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Check if database exists
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
        $stmt->execute(['name' => $this->dbName]);

        $exists = (bool) $stmt->fetchColumn();

        if (!$exists) {
            try {
                // Quote database name safely (avoid double quotes injection)
                $dbName = str_replace('"', '', $this->dbName);
                $pdo->exec('CREATE DATABASE "' . $dbName . '" WITH ENCODING \'UTF8\'');
            } catch (\Throwable) {
                // Ignore creation failure (e.g., insufficient privileges); later connection may still succeed in CI where DB is pre-created
            }
        }
    }

    private function preparePostgreSQLSchema(): void
    {
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->dbName);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Ensure clean state
        $pdo->exec('DROP TABLE IF EXISTS posts');
        $pdo->exec('DROP TABLE IF EXISTS users');

        // Create users table
        $pdo->exec('
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Create posts table
        $pdo->exec('
            CREATE TABLE posts (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Create index
        $pdo->exec('CREATE INDEX idx_posts_user_id ON posts(user_id)');
    }

    private function seedPostgreSQLTestData(): void
    {
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->dbName);
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
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: ' . $result->getError());
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
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: ' . $result->getError());
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
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: ' . $result->getError());
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
        $config->setOption('data_only', true);
        $config->setOption('verbose', true);

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Modify the database
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->dbName);
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
            'connection_name' => 'pgsql',
            'keep_original' => true,
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
                $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->host, $this->port);
                $pdo = new \PDO($dsn, $this->user, $this->password);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // Terminate connections and drop database
                $pdo->exec("
                    SELECT pg_terminate_backend(pg_stat_activity.pid)
                    FROM pg_stat_activity
                    WHERE pg_stat_activity.datname = '{$this->dbName}'
                    AND pid <> pg_backend_pid()
                ");
                $pdo->exec("DROP DATABASE IF EXISTS {$this->dbName}");
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }
}
