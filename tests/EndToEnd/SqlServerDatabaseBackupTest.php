<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * End-to-end test for SQL Server database backup functionality.
 */
class SqlServerDatabaseBackupTest extends AbstractEndToEndTest
{
    private string $testDatabase = 'test_sqlserver_backup';
    private string $host = '127.0.0.1';
    private int $port = 1433;
    private string $user = 'sa';
    private string $password = 'YourStrong@Passw0rd';

    protected function setupTest(): void
    {
        // Skip test if SQL Server is not available
        if (!$this->isSqlServerAvailable()) {
            $this->markTestSkipped('SQL Server is not available');
        }

        // Create test database and tables
        $this->createSqlServerTestDatabase();
        $this->seedSqlServerTestData();
    }

    private function isSqlServerAvailable(): bool
    {
        try {
            $dsn = sprintf('sqlsrv:Server=%s,%d;Database=master', $this->host, $this->port);
            $pdo = new \PDO($dsn, $this->user, $this->password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createSqlServerTestDatabase(): void
    {
        // Connect to master database
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=master', $this->host, $this->port);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Drop database if exists (set to single user first to kill connections)
        try {
            $pdo->exec("
                IF EXISTS (SELECT name FROM sys.databases WHERE name = '{$this->testDatabase}')
                BEGIN
                    ALTER DATABASE [{$this->testDatabase}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
                    DROP DATABASE [{$this->testDatabase}];
                END
            ");
        } catch (\Throwable) {
            // Ignore errors if database doesn't exist
        }

        // Create database
        $pdo->exec("CREATE DATABASE [{$this->testDatabase}]");

        // Connect to the new database
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $this->host, $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create users table
        $pdo->exec("
            CREATE TABLE users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(255) NOT NULL,
                email NVARCHAR(255) NOT NULL UNIQUE,
                created_at DATETIME2 DEFAULT GETDATE()
            )
        ");

        // Create posts table
        $pdo->exec("
            CREATE TABLE posts (
                id INT IDENTITY(1,1) PRIMARY KEY,
                user_id INT NOT NULL,
                title NVARCHAR(255) NOT NULL,
                content NVARCHAR(MAX),
                created_at DATETIME2 DEFAULT GETDATE(),
                CONSTRAINT FK_posts_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Create index
        $pdo->exec('CREATE INDEX idx_posts_user_id ON posts(user_id)');
    }

    private function seedSqlServerTestData(): void
    {
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $this->host, $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Insert test users
        $pdo->exec("
            INSERT INTO users (name, email) VALUES
            (N'John Doe', N'john@example.com'),
            (N'Jane Smith', N'jane@example.com'),
            (N'Bob Johnson', N'bob@example.com')
        ");

        // Insert test posts
        $pdo->exec("
            INSERT INTO posts (user_id, title, content) VALUES
            (1, N'First Post', N'This is the first post content'),
            (1, N'Second Post', N'This is the second post content'),
            (2, N'Hello World', N'Hello world from Jane'),
            (3, N'SQL Server Backup Test', N'Testing SQL Server backup functionality')
        ");
    }

    public function testSqlServerBackupWithGzipCompression(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setConnectionName('sqlserver');
        $config->setCompression('gzip');
        $config->setName('sqlserver_backup_test');

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
        $this->assertStringEndsWith('.tar.gz', $result->getFilePath(), 'Backup should be gzip compressed');
    }

    public function testSqlServerBackupWithZipCompression(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('database');
        $config->setConnectionName('sqlserver');
        $config->setCompression('zip');
        $config->setName('sqlserver_backup_zip_test');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: '.$result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');

        // Verify it's a zip file
        $this->assertStringEndsWith('.zip', $result->getFilePath(), 'Backup should be zip compressed');
    }

    public function testSqlServerBackupWithoutCompression(): void
    {
        // Create backup configuration without compression
        $config = new BackupConfiguration('database');
        $config->setConnectionName('sqlserver');
        $config->setName('sqlserver_backup_no_compression_test');
        // No compression

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful: '.$result->getError());
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');

        // Verify it's a .bak file (SQL Server backup format)
        $this->assertStringEndsWith('.bak', $result->getFilePath(), 'Backup should be .bak file');
    }

    public function testSqlServerBackupAndRestore(): void
    {
        // Create backup
        $config = new BackupConfiguration('database');
        $config->setConnectionName('sqlserver');
        $config->setCompression('gzip');
        $config->setName('sqlserver_restore_test');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Modify the database
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $this->host, $this->port, $this->testDatabase);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('DELETE FROM posts');
        $pdo->exec('DELETE FROM users');

        // Verify data is gone
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(0, $row['count'], 'Users table should be empty');

        // Close connection before restore
        unset($pdo);

        // Restore from backup
        $restoreResult = $this->backupManager->restore($result->getId(), [
            'connection_name' => 'sqlserver',
            'single_user' => true, // Set database to single user mode during restore
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
        $stmt = $pdo->query("SELECT name FROM users WHERE email = N'john@example.com'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('John Doe', $row['name'], 'User data should match');

        $stmt = $pdo->query('SELECT title FROM posts WHERE user_id = 3');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('SQL Server Backup Test', $row['title'], 'Post data should match');
    }

    public function testSqlServerBackupIntegrity(): void
    {
        // Create backup
        $config = new BackupConfiguration('database');
        $config->setConnectionName('sqlserver');
        $config->setCompression('gzip');
        $config->setName('sqlserver_integrity_test');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Verify backup file integrity (can be opened)
        $this->assertTrue($this->filesystem->exists($result->getFilePath()), 'Backup file should exist');
        $this->assertGreaterThan(1000, filesize($result->getFilePath()), 'Backup file should have reasonable size');
    }

    protected function tearDown(): void
    {
        // Cleanup test database
        if ($this->isSqlServerAvailable()) {
            try {
                $dsn = sprintf('sqlsrv:Server=%s,%d;Database=master', $this->host, $this->port);
                $pdo = new \PDO($dsn, $this->user, $this->password);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // Drop database (set to single user first)
                $pdo->exec("
                    IF EXISTS (SELECT name FROM sys.databases WHERE name = '{$this->testDatabase}')
                    BEGIN
                        ALTER DATABASE [{$this->testDatabase}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
                        DROP DATABASE [{$this->testDatabase}];
                    END
                ");
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }
}
