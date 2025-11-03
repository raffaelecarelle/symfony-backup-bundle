<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Database\SqlServerAdapter;
use ProBackupBundle\Model\BackupConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class SqlServerAdapterTest extends TestCase
{
    private $tempDir;
    private $mockConnection;
    private $mockLogger;
    private $adapter;
    private $filesystem;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/sqlserver_adapter_test_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockConnection = $this->createMock(Connection::class);
        $this->mockConnection->method('getDatabase')->willReturn('test_db');

        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->filesystem = new Filesystem();

        $this->adapter = new SqlServerAdapter($this->mockConnection, $this->mockLogger);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testGetConnection(): void
    {
        $this->assertSame($this->mockConnection, $this->adapter->getConnection());
    }

    public function testSupports(): void
    {
        $this->assertTrue($this->adapter->supports('sqlserver'));
        $this->assertTrue($this->adapter->supports('mssql'));
        $this->assertTrue($this->adapter->supports('database'));
        $this->assertFalse($this->adapter->supports('mysql'));
        $this->assertFalse($this->adapter->supports('postgresql'));
    }

    public function testValidate(): void
    {
        $config = new BackupConfiguration();
        $config->setOutputPath($this->tempDir.'/backups');

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

    /**
     * Test the backup method with mocked connection.
     */
    public function testBackup(): void
    {
        // Create a mock connection that will simulate a successful backup
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getDatabase')->willReturn('test_db');
        $mockConnection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('BACKUP DATABASE'));

        $adapter = new SqlServerAdapter($mockConnection, $this->mockLogger);

        // Create a backup configuration
        $outputPath = $this->tempDir.'/backups';
        $this->filesystem->mkdir($outputPath);

        $config = new BackupConfiguration();
        $config->setOutputPath($outputPath);
        $config->setName('test_backup');

        // Create a fake backup file that would be created by SQL Server
        $expectedFilePath = $outputPath.'/test_db_test_backup_'.date('Y-m-d_H-i-s').'.bak';
        file_put_contents($expectedFilePath, 'SQL Server backup data');

        // Execute the backup
        $result = $adapter->backup($config);

        // Verify the result
        $this->assertTrue($result->isSuccess());
        $this->assertEquals($expectedFilePath, $result->getFilePath());
        $this->assertEquals(filesize($expectedFilePath), $result->getSize());
    }

    /**
     * Test the backup method with an exception.
     */
    public function testBackupWithException(): void
    {
        // Create a mock connection that will throw an exception
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getDatabase')->willReturn('test_db');
        $mockConnection->method('executeStatement')
            ->willThrowException(new \Exception('Database backup failed'));

        $adapter = new SqlServerAdapter($mockConnection, $this->mockLogger);

        // Create a backup configuration
        $outputPath = $this->tempDir.'/backups';
        $this->filesystem->mkdir($outputPath);

        $config = new BackupConfiguration();
        $config->setOutputPath($outputPath);

        // Execute the backup
        $result = $adapter->backup($config);

        // Verify the result
        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getFilePath());
        $this->assertNotNull($result->getError());
        $this->assertStringContainsString('Database backup failed', $result->getError());
    }

    /**
     * Test the restore method with mocked connection.
     */
    public function testRestore(): void
    {
        // Create a mock connection that will simulate a successful restore
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getDatabase')->willReturn('test_db');
        $mockConnection->expects($this->exactly(3))
            ->method('executeStatement')
            ->withConsecutive(
                [$this->stringContains('ALTER DATABASE [test_db] SET SINGLE_USER')],
                [$this->stringContains('RESTORE DATABASE [test_db]')],
                [$this->stringContains('ALTER DATABASE [test_db] SET MULTI_USER')]
            );

        $adapter = new SqlServerAdapter($mockConnection, $this->mockLogger);

        // Create a backup file
        $backupPath = $this->tempDir.'/test_backup.bak';
        file_put_contents($backupPath, 'SQL Server backup data');

        // Execute the restore
        $result = $adapter->restore($backupPath);

        // Verify the result
        $this->assertTrue($result);
    }

    /**
     * Test the restore method with an exception.
     */
    public function testRestoreWithException(): void
    {
        // Create a mock connection that will throw an exception
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getDatabase')->willReturn('test_db');
        $mockConnection->method('executeStatement')
            ->willThrowException(new \Exception('Database restore failed'));

        $adapter = new SqlServerAdapter($mockConnection, $this->mockLogger);

        // Create a backup file
        $backupPath = $this->tempDir.'/test_backup.bak';
        file_put_contents($backupPath, 'SQL Server backup data');

        // Execute the restore
        $result = $adapter->restore($backupPath);

        // Verify the result
        $this->assertFalse($result);
    }

    /**
     * Test the restore method with no single user mode.
     */
    public function testRestoreWithoutSingleUserMode(): void
    {
        // Create a mock connection that will simulate a successful restore
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getDatabase')->willReturn('test_db');
        $mockConnection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('RESTORE DATABASE [test_db]'));

        $adapter = new SqlServerAdapter($mockConnection, $this->mockLogger);

        // Create a backup file
        $backupPath = $this->tempDir.'/test_backup.bak';
        file_put_contents($backupPath, 'SQL Server backup data');

        // Execute the restore with single_user option set to false
        $result = $adapter->restore($backupPath, ['single_user' => false]);

        // Verify the result
        $this->assertTrue($result);
    }

    /**
     * Test the restore method with NORECOVERY option.
     */
    public function testRestoreWithNoRecovery(): void
    {
        // Create a mock connection that will simulate a successful restore
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getDatabase')->willReturn('test_db');
        $mockConnection->expects($this->exactly(3))
            ->method('executeStatement')
            ->withConsecutive(
                [$this->stringContains('ALTER DATABASE [test_db] SET SINGLE_USER')],
                [$this->stringContains('RESTORE DATABASE [test_db]') && $this->stringContains('NORECOVERY')],
                [$this->stringContains('ALTER DATABASE [test_db] SET MULTI_USER')]
            );

        $adapter = new SqlServerAdapter($mockConnection, $this->mockLogger);

        // Create a backup file
        $backupPath = $this->tempDir.'/test_backup.bak';
        file_put_contents($backupPath, 'SQL Server backup data');

        // Execute the restore with recovery option set to norecovery
        $result = $adapter->restore($backupPath, ['recovery' => 'norecovery']);

        // Verify the result
        $this->assertTrue($result);
    }

    /**
     * Test the generateFilename method.
     *
     * This test uses reflection to access the private method
     */
    public function testGenerateFilename(): void
    {
        $config = new BackupConfiguration();
        $config->setName('test_backup');

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(SqlServerAdapter::class);
        $method = $reflectionClass->getMethod('generateFilename');

        $filename = $method->invoke($this->adapter, $config);

        // Verify the filename format
        $this->assertStringStartsWith('test_db_test_backup_', $filename);
        $this->assertStringEndsWith('.bak', $filename);
        $this->assertMatchesRegularExpression('/test_db_test_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.bak/', $filename);
    }
}
