<?php

namespace Symfony\Component\Backup\Tests\Adapter\Storage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Backup\Adapter\Storage\LocalAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;

class LocalAdapterTest extends TestCase
{
    private $tempDir;
    private $adapter;
    private $mockLogger;
    private $mockFilesystem;
    private $mockFinder;
    
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/backup_storage_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockFilesystem = $this->createMock(Filesystem::class);
        
        // Create a real adapter with mocked dependencies
        $this->adapter = new LocalAdapter($this->tempDir, 0755, $this->mockLogger);
        
        // Replace the filesystem with our mock
        $reflectionProperty = new \ReflectionProperty(LocalAdapter::class, 'filesystem');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->adapter, $this->mockFilesystem);
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
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }
    
    public function testStore(): void
    {
        // Create a test file
        $localPath = $this->tempDir . '/test_file.txt';
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
            )
            ->willReturn(true);
        
        $result = $this->adapter->store($localPath, $remotePath);
        
        $this->assertTrue($result);
    }
    
    public function testRetrieve(): void
    {
        $remotePath = 'backups/test_file.txt';
        $localPath = $this->tempDir . '/retrieved_file.txt';
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->once())
            ->method('exists')
            ->with($this->stringContains($remotePath))
            ->willReturn(true);
            
        $this->mockFilesystem->expects($this->once())
            ->method('copy')
            ->with(
                $this->stringContains($remotePath),
                $this->equalTo($localPath)
            )
            ->willReturn(true);
        
        $result = $this->adapter->retrieve($remotePath, $localPath);
        
        $this->assertTrue($result);
    }
    
    public function testRetrieveNonExistentFile(): void
    {
        $remotePath = 'backups/non_existent_file.txt';
        $localPath = $this->tempDir . '/retrieved_file.txt';
        
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
        
        $this->assertFalse($result);
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
        
        // Create a mock Finder
        $this->mockFinder = $this->getMockBuilder(Finder::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $this->mockFinder->expects($this->once())
            ->method('files')
            ->willReturnSelf();
            
        $this->mockFinder->expects($this->once())
            ->method('in')
            ->with($this->stringContains($prefix))
            ->willReturnSelf();
            
        $this->mockFinder->expects($this->once())
            ->method('sortByName')
            ->willReturnSelf();
        
        // Mock the iterator
        $mockFile1 = $this->createMock(\SplFileInfo::class);
        $mockFile1->method('getPathname')->willReturn($this->tempDir . '/backups/file1.txt');
        $mockFile1->method('getRelativePathname')->willReturn('file1.txt');
        $mockFile1->method('getMTime')->willReturn(time());
        $mockFile1->method('getSize')->willReturn(100);
        
        $mockFile2 = $this->createMock(\SplFileInfo::class);
        $mockFile2->method('getPathname')->willReturn($this->tempDir . '/backups/file2.txt');
        $mockFile2->method('getRelativePathname')->willReturn('file2.txt');
        $mockFile2->method('getMTime')->willReturn(time());
        $mockFile2->method('getSize')->willReturn(200);
        
        $mockIterator = new \ArrayIterator([$mockFile1, $mockFile2]);
        
        $this->mockFinder->method('getIterator')
            ->willReturn($mockIterator);
        
        // Replace the Finder creation in the adapter
        $this->mockMethod($this->adapter, 'createFinder', function() {
            return $this->mockFinder;
        });
        
        $result = $this->adapter->list($prefix);
        
        $this->assertCount(2, $result);
        $this->assertEquals('file1.txt', $result[0]['name']);
        $this->assertEquals(100, $result[0]['size']);
        $this->assertEquals('file2.txt', $result[1]['name']);
        $this->assertEquals(200, $result[1]['size']);
    }
    
    /**
     * Helper method to mock a method in an object
     */
    private function mockMethod($object, $method, $callback)
    {
        $reflectionMethod = new \ReflectionMethod(get_class($object), $method);
        $reflectionMethod->setAccessible(true);
        
        $closure = \Closure::bind(function($object, $method, $callback) {
            $object->$method = $callback;
        }, null, $object);
        
        $closure($object, $method, $callback);
    }
}