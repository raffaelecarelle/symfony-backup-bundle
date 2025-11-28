<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Database\SQLiteAdapter;
use ProBackupBundle\Exception\BackupException;
use ProBackupBundle\Model\BackupConfiguration;
use Psr\Log\LoggerInterface;

class SQLiteAdapterTest extends TestCase
{
    private string $tempDir;

    private MockObject $mockConnection;

    private MockObject $mockLogger;

    private SQLiteAdapter $adapter;

    private string $dbPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sqlite_adapter_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // Create a test SQLite database file
        $this->dbPath = $this->tempDir . '/test.sqlite';
        file_put_contents($this->dbPath, 'SQLite format 3'); // Minimal SQLite file header

        $this->mockConnection = $this->createMock(Connection::class);
        $this->mockConnection->method('getParams')->willReturn(['path' => $this->dbPath]);

        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->adapter = new SQLiteAdapter($this->mockConnection, $this->mockLogger);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testGetConnection(): void
    {
        $this->assertSame($this->mockConnection, $this->adapter->getConnection());
    }

    public function testBackup(): void
    {
        $outputPath = $this->tempDir . '/backups';
        $config = new BackupConfiguration();
        $config->setOutputPath($outputPath);
        $config->setName('test_backup');

        $result = $this->adapter->backup($config);

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->getFilePath());
        $this->assertFileExists($result->getFilePath());
        $this->assertEquals(filesize($this->dbPath), $result->getSize());
    }

    public function testBackupWithNonExistentDatabase(): void
    {
        // Create a new connection with a non-existent database path
        $nonExistentPath = $this->tempDir . '/non_existent.sqlite';
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getParams')->willReturn(['path' => $nonExistentPath]);

        $adapter = new SQLiteAdapter($mockConnection, $this->mockLogger);

        $outputPath = $this->tempDir . '/backups';
        $config = new BackupConfiguration();
        $config->setOutputPath($outputPath);

        $result = $adapter->backup($config);

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getFilePath());
        $this->assertNotNull($result->getError());
        $this->assertStringContainsString('not found', $result->getError());
    }

    public function testRestore(): void
    {
        // Create a backup file
        $backupPath = $this->tempDir . '/backup.sqlite';
        file_put_contents($backupPath, 'SQLite format 3 BACKUP');

        $result = $this->adapter->restore($backupPath);

        $this->assertTrue($result);
        $this->assertFileExists($this->dbPath);
        $this->assertEquals('SQLite format 3 BACKUP', file_get_contents($this->dbPath));

        // Check that a backup of the original was created
        $backupFiles = glob($this->dbPath . '.bak.*');
        $this->assertNotEmpty($backupFiles);
    }

    public function testRestoreWithoutBackupExisting(): void
    {
        // Create a backup file
        $backupPath = $this->tempDir . '/backup.sqlite';
        file_put_contents($backupPath, 'SQLite format 3 BACKUP');

        $result = $this->adapter->restore($backupPath, ['backup_existing' => false]);

        $this->assertTrue($result);
        $this->assertFileExists($this->dbPath);

        // Check that no backup of the original was created
        $backupFiles = glob($this->dbPath . '.bak.*');
        $this->assertEmpty($backupFiles);
    }

    public function testRestoreWithNonExistentBackup(): void
    {
        $nonExistentBackup = $this->tempDir . '/non_existent_backup.sqlite';

        $result = $this->adapter->restore($nonExistentBackup);

        $this->assertFalse($result);
    }

    public function testSupports(): void
    {
        $this->assertTrue($this->adapter->supports('sqlite'));
        $this->assertTrue($this->adapter->supports('database'));
        $this->assertFalse($this->adapter->supports('mysql'));
        $this->assertFalse($this->adapter->supports('postgresql'));
    }

    public function testValidate(): void
    {
        $config = new BackupConfiguration();
        $config->setOutputPath($this->tempDir . '/backups');

        $errors = $this->adapter->validate($config);

        $this->assertEmpty($errors);
    }

    public function testValidateWithMissingOutputPath(): void
    {
        $config = new BackupConfiguration();

        $errors = $this->adapter->validate($config);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Output path is not specified', $errors[0]);
    }

    public function testValidateWithNonExistentDatabase(): void
    {
        // Create a new connection with a non-existent database path
        $nonExistentPath = $this->tempDir . '/non_existent.sqlite';
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getParams')->willReturn(['path' => $nonExistentPath]);

        $adapter = new SQLiteAdapter($mockConnection, $this->mockLogger);

        $config = new BackupConfiguration();
        $config->setOutputPath($this->tempDir . '/backups');

        $errors = $adapter->validate($config);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not found', $errors[0]);
    }

    public function testGetDatabasePathFromUrl(): void
    {
        // Create a new connection with URL parameter
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getParams')->willReturn([
            'url' => 'sqlite:///' . $this->dbPath,
        ]);

        $adapter = new SQLiteAdapter($mockConnection, $this->mockLogger);

        $config = new BackupConfiguration();
        $config->setOutputPath($this->tempDir . '/backups');

        $result = $adapter->backup($config);

        $this->assertTrue($result->isSuccess());
    }

    public function testGetDatabasePathException(): void
    {
        // Create a new connection with invalid parameters
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getParams')->willReturn([
            'driver' => 'sqlite',
            // No path or URL
        ]);

        $adapter = new SQLiteAdapter($mockConnection, $this->mockLogger);

        $config = new BackupConfiguration();
        $config->setOutputPath($this->tempDir . '/backups');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Could not determine SQLite database path');

        $adapter->backup($config);
    }
}
