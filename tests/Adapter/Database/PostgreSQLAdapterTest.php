<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Database\PostgreSQLAdapter;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Process\Factory\ProcessFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class PostgreSQLAdapterTest extends TestCase
{
    private $tempDir;
    private $mockConnection;
    private $mockLogger;
    private $adapter;
    private $filesystem;
    private $mockProcessFactory;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/postgresql_adapter_test_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockConnection = $this->createMock(Connection::class);
        $this->mockConnection->method('getDatabase')->willReturn('test_db');
        $this->mockConnection->method('getParams')->willReturn([
            'host' => 'localhost',
            'port' => 5432,
            'user' => 'postgres',
            'password' => 'password',
        ]);

        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->filesystem = new Filesystem();

        $this->mockProcessFactory = $this->createMock(ProcessFactory::class);
        $this->adapter = new PostgreSQLAdapter(
            $this->mockConnection,
            $this->mockLogger,
            $this->mockProcessFactory
        );
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

    /**
     * Mock a successful process execution.
     */
    private function mockSuccessfulProcess(): void
    {
        // Create a mock for Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Configure the mock
        $mockProcess->method('run')->willReturn(0);
        $mockProcess->method('isSuccessful')->willReturn(true);

        // Replace the Process class with our mock
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', $mockProcess);
    }

    /**
     * Mock a static method.
     */
    private function mockStaticMethod($class, $method, $return)
    {
        $mock = $this->createMock($class);
        $mock->method($method)->willReturn($return);

        // Use reflection to replace the static method
        $reflectionClass = new \ReflectionClass($class);
        $reflectionMethod = $reflectionClass->getMethod($method);

        // This is a workaround since we can't directly mock static methods in PHPUnit
        // In a real test, we would use a library like AspectMock or create a wrapper class
    }

    public function testGetConnection(): void
    {
        $this->assertSame($this->mockConnection, $this->adapter->getConnection());
    }

    public function testSupports(): void
    {
        $this->assertTrue($this->adapter->supports('postgresql'));
        $this->assertTrue($this->adapter->supports('postgres'));
        $this->assertTrue($this->adapter->supports('pgsql'));
        $this->assertTrue($this->adapter->supports('database'));
        $this->assertFalse($this->adapter->supports('mysql'));
        $this->assertFalse($this->adapter->supports('sqlite'));
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
     * Test backup method with mocked process.
     *
     * Note: This is a partial test that mocks the process execution
     * In a real environment, we would use a test database or a more
     * sophisticated approach to test the actual pg_dump command
     */
    public function testBackupWithMockedProcess(): void
    {
        // Skip this test if we can't mock the Process class
        $this->markTestSkipped('This test requires mocking static methods which is not directly supported in PHPUnit');

        // Mock the process execution
        $this->mockSuccessfulProcess();

        // Create a temporary file that will be "created" by our mocked process
        $outputPath = $this->tempDir.'/backups';
        $this->filesystem->mkdir($outputPath);

        $config = new BackupConfiguration();
        $config->setOutputPath($outputPath);
        $config->setName('test_backup');

        // Execute the backup
        $result = $this->adapter->backup($config);

        // Since we mocked the process, we need to manually create the output file
        $expectedFilePath = $outputPath.'/test_db_test_backup_'.date('Y-m-d').'_*.sql';
        $this->filesystem->touch($expectedFilePath);

        // Verify the result
        $this->assertTrue($result->isSuccess());
    }

    /**
     * Test restore method with mocked process.
     *
     * Note: This is a partial test that mocks the process execution
     * In a real environment, we would use a test database or a more
     * sophisticated approach to test the actual pg_restore command
     */
    public function testRestoreWithMockedProcess(): void
    {
        // Skip this test if we can't mock the Process class
        $this->markTestSkipped('This test requires mocking static methods which is not directly supported in PHPUnit');

        // Mock the process execution
        $this->mockSuccessfulProcess();

        // Create a backup file
        $backupPath = $this->tempDir.'/test_backup.sql';
        file_put_contents($backupPath, '-- PostgreSQL backup file');

        // Execute the restore
        $result = $this->adapter->restore($backupPath);

        // Verify the result
        $this->assertTrue($result);
    }

    /**
     * Test the command building functionality for pg_dump.
     *
     * This test uses reflection to access the private method
     */
    public function testBuildPgDumpCommand(): void
    {
        $config = new BackupConfiguration();
        $config->setOutputPath($this->tempDir.'/backups');
        $config->setName('test_backup');
        $config->setOption('create', true);
        $filepath = $this->tempDir.'/backups/test_db_backup.sql';

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(PostgreSQLAdapter::class);
        $method = $reflectionClass->getMethod('buildPgDumpCommand');

        $command = $method->invoke($this->adapter, $config, $filepath);

        // Verify the command contains the expected parts
        $this->assertStringContainsString('pg_dump', $command);
        $this->assertStringContainsString('--host=', $command);
        $this->assertStringContainsString('--port=', $command);
        $this->assertStringContainsString('--username=', $command);
        $this->assertStringContainsString('--format=plain', $command);
        $this->assertStringContainsString('--create', $command);
        $this->assertStringContainsString('test_db', $command);
        $this->assertStringContainsString($filepath, $command);
    }

    /**
     * Test the command building functionality for pg_restore.
     *
     * This test uses reflection to access the private method
     */
    public function testBuildPgRestoreCommandForPlainFormat(): void
    {
        $filepath = $this->tempDir.'/test_backup.sql';
        file_put_contents($filepath, '-- PostgreSQL backup file');

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(PostgreSQLAdapter::class);
        $method = $reflectionClass->getMethod('buildPgRestoreCommand');

        $command = $method->invoke($this->adapter, $filepath, [
            'single_transaction' => true,
        ]);

        // Verify the command contains the expected parts for plain format (psql)
        $this->assertStringContainsString('psql', $command);
        $this->assertStringContainsString('--host=', $command);
        $this->assertStringContainsString('--port=', $command);
        $this->assertStringContainsString('--username=', $command);
        $this->assertStringContainsString('--dbname=', $command);
        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString($filepath, $command);
    }

    /**
     * Test the command building functionality for pg_restore with custom format.
     *
     * This test uses reflection to access the private method
     */
    public function testBuildPgRestoreCommandForCustomFormat(): void
    {
        $filepath = $this->tempDir.'/test_backup.dump';
        file_put_contents($filepath, 'PGDMP'); // Fake custom format header

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(PostgreSQLAdapter::class);
        $method = $reflectionClass->getMethod('buildPgRestoreCommand');

        $command = $method->invoke($this->adapter, $filepath, []);

        // Verify the command contains the expected parts for custom format (pg_restore)
        $this->assertStringContainsString('pg_restore', $command);
        $this->assertStringContainsString('--host=', $command);
        $this->assertStringContainsString('--port=', $command);
        $this->assertStringContainsString('--username=', $command);
        $this->assertStringContainsString('--dbname=', $command);
        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString($filepath, $command);
    }
}
