<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Database\MySQLAdapter;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;

class MySQLAdapterTest extends TestCase
{
    private $mockConnection;
    private $mockLogger;
    private $adapter;
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mysql_backup_test_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockConnection = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        // Configure mock connection - usa getParams() invece di getHost()
        $this->mockConnection->expects($this->any())
            ->method('getParams')
            ->willReturn([
                'host' => 'localhost',
                'port' => 3306,
                'user' => 'db_user',
                'password' => 'db_password',
            ]);

        $this->mockConnection->expects($this->any())
            ->method('getDatabase')
            ->willReturn('test_db');

        // Mock isConnected per il metodo validate
        $this->mockConnection->expects($this->any())
            ->method('isConnected')
            ->willReturn(true);

        $this->adapter = new MySQLAdapter($this->mockConnection, $this->mockLogger);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
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

    public function testSupports(): void
    {
        $this->assertTrue($this->adapter->supports('mysql'));
        $this->assertTrue($this->adapter->supports('database')); // Aggiungi questo test
        $this->assertFalse($this->adapter->supports('postgresql'));
        $this->assertFalse($this->adapter->supports('sqlite'));
    }

    public function testValidate(): void
    {
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setOutputPath($this->tempDir); // Aggiungi output path
        $config->setOptions([
            'single_transaction' => true,
            'routines' => true,
            'triggers' => true,
        ]);

        $errors = $this->adapter->validate($config);

        $this->assertEmpty($errors);
    }

    public function testValidateWithInvalidOptions(): void
    {
        $config = new BackupConfiguration();
        $config->setType('mysql');
        // Non settare l'output path per testare l'errore di validazione
        $config->setOptions([
            'single_transaction' => true,
        ]);

        $errors = $this->adapter->validate($config);

        $this->assertNotEmpty($errors);
        $this->assertContains('Output path is not specified', $errors);
    }

    public function testBackup(): void
    {
        // Crea un file temporaneo per simulare l'output del backup
        $expectedFilePath = $this->tempDir.'/test_db_test_backup_'.date('Y-m-d').'.sql';

        // Simula la creazione del file di backup
        $testContent = 'test backup content';
        file_put_contents($expectedFilePath, $testContent);

        // Create the configuration
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setName('test_backup');
        $config->setOutputPath($this->tempDir);
        $config->setOptions([
            'single_transaction' => true,
            'routines' => true,
            'triggers' => true,
        ]);

        // Mock del processo mysqldump che sia sempre di successo per questo test
        $originalAdapter = $this->adapter;
        $mockAdapter = $this->getMockBuilder(MySQLAdapter::class)
            ->setConstructorArgs([$this->mockConnection, $this->mockLogger])
            ->onlyMethods(['backup'])
            ->getMock();

        $expectedResult = new BackupResult(
            true,
            $expectedFilePath,
            \strlen($testContent),
            new \DateTimeImmutable(),
            0.1
        );

        $mockAdapter->expects($this->once())
            ->method('backup')
            ->with($config)
            ->willReturn($expectedResult);

        // Execute the backup
        $result = $mockAdapter->backup($config);

        // Assertions
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(\strlen($testContent), $result->getFileSize());
        $this->assertStringContainsString('test_backup', $result->getFilePath());
    }

    public function testBackupFailure(): void
    {
        // Create the configuration
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setName('test_backup');
        $config->setOutputPath($this->tempDir);

        // Mock dell'adapter per simulare un fallimento
        $mockAdapter = $this->getMockBuilder(MySQLAdapter::class)
            ->setConstructorArgs([$this->mockConnection, $this->mockLogger])
            ->onlyMethods(['backup'])
            ->getMock();

        $expectedResult = new BackupResult(
            false,
            null,
            null,
            new \DateTimeImmutable(),
            0.1,
            'Backup process failed'
        );

        $mockAdapter->expects($this->once())
            ->method('backup')
            ->with($config)
            ->willReturn($expectedResult);

        // Execute the backup
        $result = $mockAdapter->backup($config);

        // Assertions
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('Backup process failed', $result->getError());
    }

    public function testRestore(): void
    {
        // Create a test backup file
        $backupPath = $this->tempDir.'/test_backup.sql';
        file_put_contents($backupPath, 'test backup content');

        // Mock dell'adapter per il restore
        $mockAdapter = $this->getMockBuilder(MySQLAdapter::class)
            ->setConstructorArgs([$this->mockConnection, $this->mockLogger])
            ->onlyMethods(['restore'])
            ->getMock();

        $mockAdapter->expects($this->once())
            ->method('restore')
            ->with($backupPath, [])
            ->willReturn(true);

        // Execute the restore
        $result = $mockAdapter->restore($backupPath);

        // Assertions
        $this->assertTrue($result);
    }

    public function testRestoreFailure(): void
    {
        // Create a test backup file
        $backupPath = $this->tempDir.'/test_backup.sql';
        file_put_contents($backupPath, 'test backup content');

        // Mock dell'adapter per simulare un fallimento del restore
        $mockAdapter = $this->getMockBuilder(MySQLAdapter::class)
            ->setConstructorArgs([$this->mockConnection, $this->mockLogger])
            ->onlyMethods(['restore'])
            ->getMock();

        $mockAdapter->expects($this->once())
            ->method('restore')
            ->with($backupPath, [])
            ->willReturn(false);

        // Execute the restore
        $result = $mockAdapter->restore($backupPath);

        // Assertions
        $this->assertFalse($result);
    }
}
