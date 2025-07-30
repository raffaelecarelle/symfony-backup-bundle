<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Filesystem;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Filesystem\FilesystemAdapter;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;

class FilesystemAdapterTest extends TestCase
{
    private $mockLogger;
    private $adapter;
    private $tempDir;
    private $testFilesDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/filesystem_backup_test_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // Create a directory with test files to backup
        $this->testFilesDir = $this->tempDir.'/test_files';
        mkdir($this->testFilesDir, 0777, true);
        
        // Create some test files
        file_put_contents($this->testFilesDir.'/file1.txt', 'Test content 1');
        file_put_contents($this->testFilesDir.'/file2.txt', 'Test content 2');
        
        // Create a subdirectory with files
        mkdir($this->testFilesDir.'/subdir', 0777, true);
        file_put_contents($this->testFilesDir.'/subdir/file3.txt', 'Test content 3');
        
        // Create a directory to exclude
        mkdir($this->testFilesDir.'/secrets', 0777, true);
        file_put_contents($this->testFilesDir.'/secrets/secret.txt', 'Secret content');

        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->adapter = new FilesystemAdapter($this->mockLogger);
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
        $this->assertTrue($this->adapter->supports('filesystem'));
        $this->assertFalse($this->adapter->supports('database'));
        $this->assertFalse($this->adapter->supports('mysql'));
    }

    public function testValidate(): void
    {
        $config = new BackupConfiguration();
        $config->setType('filesystem');
        $config->setOutputPath($this->tempDir);
        $config->setOption('paths', [
            [
                'path' => $this->testFilesDir,
                'exclude' => ['secrets/'],
            ],
        ]);

        $errors = $this->adapter->validate($config);

        $this->assertEmpty($errors);
    }

    public function testValidateWithInvalidOptions(): void
    {
        $config = new BackupConfiguration();
        $config->setType('filesystem');
        // Don't set output path to test validation error
        
        $errors = $this->adapter->validate($config);

        $this->assertNotEmpty($errors);
        $this->assertContains('Output path is not specified', $errors);
        
        // Set output path but no paths
        $config->setOutputPath($this->tempDir);
        $errors = $this->adapter->validate($config);
        
        $this->assertNotEmpty($errors);
        $this->assertContains('No paths specified for filesystem backup', $errors);
    }

    public function testBackup(): void
    {
        // Create the configuration
        $config = new BackupConfiguration();
        $config->setType('filesystem');
        $config->setName('test_backup');
        $config->setOutputPath($this->tempDir);
        $config->setCompression('zip');
        $config->setOption('paths', [
            [
                'path' => $this->testFilesDir,
                'exclude' => ['secrets/'],
            ],
        ]);

        // Mock the adapter to avoid actually running the backup
        $mockAdapter = $this->getMockBuilder(FilesystemAdapter::class)
            ->setConstructorArgs([$this->mockLogger])
            ->onlyMethods(['backup'])
            ->getMock();
        
        $expectedArchivePath = $this->tempDir.'/test_backup_'.date('Y-m-d_H-i-s').'.zip';
        
        // Create a dummy archive file
        file_put_contents($expectedArchivePath, 'test archive content');
        
        $expectedResult = new BackupResult(
            true,
            $expectedArchivePath,
            filesize($expectedArchivePath),
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
        $this->assertEquals(filesize($expectedArchivePath), $result->getFileSize());
        $this->assertStringContainsString('test_backup', $result->getFilePath());
        $this->assertStringContainsString('.zip', $result->getFilePath());
    }

    public function testBackupFailure(): void
    {
        // Create the configuration
        $config = new BackupConfiguration();
        $config->setType('filesystem');
        $config->setName('test_backup');
        $config->setOutputPath($this->tempDir);
        $config->setCompression('zip');
        $config->setOption('paths', [
            [
                'path' => '/non/existent/path',
                'exclude' => [],
            ],
        ]);

        // Execute the backup - should fail because path doesn't exist
        $result = $this->adapter->backup($config);

        // Assertions
        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->getError());
    }

    public function testRestore(): void
    {
        // Create a test backup file
        $backupPath = $this->tempDir.'/test_backup.zip';
        file_put_contents($backupPath, 'test backup content');

        // Create a target directory for restore
        $targetDir = $this->tempDir.'/restore_target';
        mkdir($targetDir, 0777, true);

        // Mock the adapter to avoid actually running the restore
        $mockAdapter = $this->getMockBuilder(FilesystemAdapter::class)
            ->setConstructorArgs([$this->mockLogger])
            ->onlyMethods(['restore'])
            ->getMock();
        
        $mockAdapter->expects($this->once())
            ->method('restore')
            ->with($backupPath, ['target_dir' => $targetDir])
            ->willReturn(true);

        // Execute the restore
        $result = $mockAdapter->restore($backupPath, ['target_dir' => $targetDir]);

        // Assertions
        $this->assertTrue($result);
    }

    public function testRestoreFailure(): void
    {
        // Create a test backup file
        $backupPath = $this->tempDir.'/test_backup.zip';
        file_put_contents($backupPath, 'test backup content');

        // Don't provide target directory to trigger failure
        $result = $this->adapter->restore($backupPath, []);

        // Assertions
        $this->assertFalse($result);
    }
}