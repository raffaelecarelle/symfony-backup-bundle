<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Storage;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Storage\LocalAdapter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class LocalAdapterTest extends TestCase
{
    private $tempDir;
    private $adapter;
    private $mockLogger;
    private $mockFilesystem;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/backup_storage_test_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockFilesystem = $this->createMock(Filesystem::class);

        // Create adapter with mocked filesystem
        $this->adapter = new LocalAdapter($this->tempDir, 0755, $this->mockLogger, $this->mockFilesystem);
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

    public function testStore(): void
    {
        // Create a test file
        $localPath = $this->tempDir.'/test_file.txt';
        file_put_contents($localPath, 'test content');

        $remotePath = 'backups/test_file.txt';

        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains('backups'))
            ->willReturn(false);

        $this->mockFilesystem->expects($this->once())
            ->method('mkdir')
            ->with($this->stringContains('backups'), 0755);

        $this->mockFilesystem->expects($this->once())
            ->method('copy')
            ->with(
                $this->equalTo($localPath),
                $this->stringContains($remotePath),
                $this->equalTo(true)
            );

        $result = $this->adapter->store($localPath, $remotePath);

        $this->assertTrue($result);
    }

    public function testRetrieve(): void
    {
        $remotePath = 'backups/test_file.txt';
        $localPath = $this->tempDir.'/retrieved_file.txt';

        // Configure mock filesystem - need to handle two exists() calls
        $this->mockFilesystem->expects($this->exactly(2))
            ->method('exists')
            ->willReturnCallback(function ($path) use ($remotePath, $localPath) {
                if (str_contains($path, $remotePath)) {
                    return true; // Source file exists
                }
                if (str_contains($path, \dirname($localPath))) {
                    return false; // Target directory doesn't exist
                }

                return false;
            });

        $this->mockFilesystem->expects($this->once())
            ->method('mkdir')
            ->with($this->equalTo(\dirname($localPath)), 0755);

        $this->mockFilesystem->expects($this->once())
            ->method('copy')
            ->with(
                $this->stringContains($remotePath),
                $this->equalTo($localPath),
                $this->equalTo(true)
            );

        $result = $this->adapter->retrieve($remotePath, $localPath);

        $this->assertTrue($result);
    }

    public function testRetrieveNonExistentFile(): void
    {
        $remotePath = 'backups/non_existent_file.txt';
        $localPath = $this->tempDir.'/retrieved_file.txt';

        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains($remotePath))
            ->willReturn(false);

        $this->mockFilesystem->expects($this->never())
            ->method('copy');

        $result = $this->adapter->retrieve($remotePath, $localPath);

        $this->assertFalse($result);
    }

    public function testDelete(): void
    {
        $remotePath = 'backups/test_file.txt';

        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains($remotePath))
            ->willReturn(true);

        $this->mockFilesystem->expects($this->once())
            ->method('remove')
            ->with($this->stringContains($remotePath));

        $result = $this->adapter->delete($remotePath);

        $this->assertTrue($result);
    }

    public function testDeleteNonExistentFile(): void
    {
        $remotePath = 'backups/non_existent_file.txt';

        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains($remotePath))
            ->willReturn(false);

        $this->mockFilesystem->expects($this->never())
            ->method('remove');

        $result = $this->adapter->delete($remotePath);

        $this->assertTrue($result);
    }

    public function testExists(): void
    {
        $remotePath = 'backups/test_file.txt';

        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains($remotePath))
            ->willReturn(true);

        $result = $this->adapter->exists($remotePath);

        $this->assertTrue($result);
    }

    public function testList(): void
    {
        $prefix = 'backups';

        // Create the directory structure for real testing
        $backupDir = $this->tempDir.'/backups';
        mkdir($backupDir, 0777, true);

        // Create some test files
        file_put_contents($backupDir.'/file1.txt', 'content1');
        file_put_contents($backupDir.'/file2.txt', 'content2');

        // Use a real filesystem for this test instead of mocking
        $realAdapter = new LocalAdapter($this->tempDir, 0755, $this->mockLogger);

        $result = $realAdapter->list($prefix);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('path', $result[0]);
        $this->assertArrayHasKey('size', $result[0]);
        $this->assertArrayHasKey('modified', $result[0]);

        // Sort results by path for predictable testing
        usort($result, fn ($a, $b) => strcmp((string) $a['path'], (string) $b['path']));

        $this->assertEquals('backups/file1.txt', $result[0]['path']);
        $this->assertEquals('backups/file2.txt', $result[1]['path']);
        $this->assertEquals(8, $result[0]['size']); // 'content1' is 8 bytes
        $this->assertEquals(8, $result[1]['size']); // 'content2' is 8 bytes
        $this->assertInstanceOf(\DateTimeImmutable::class, $result[0]['modified']);
    }

    public function testListEmptyDirectory(): void
    {
        $prefix = 'empty';

        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains($prefix))
            ->willReturn(false);

        $result = $this->adapter->list($prefix);

        $this->assertEmpty($result);
    }
}
