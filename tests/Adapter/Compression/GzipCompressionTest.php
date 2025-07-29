<?php

namespace Symfony\Component\Backup\Tests\Adapter\Compression;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Backup\Adapter\Compression\GzipCompression;
use Symfony\Component\Backup\Exception\BackupException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

class GzipCompressionTest extends TestCase
{
    private $tempDir;
    private $adapter;
    private $mockLogger;
    private $mockFilesystem;
    
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/gzip_compression_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockFilesystem = $this->createMock(Filesystem::class);
        
        // Create a real adapter with mocked dependencies
        $this->adapter = new GzipCompression(6, true, $this->mockLogger);
        
        // Replace the filesystem with our mock
        $reflectionProperty = new \ReflectionProperty(GzipCompression::class, 'filesystem');
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
    
    public function testCompress(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');
        
        $targetPath = $this->tempDir . '/test_file.txt.gz';
        
        // Create a mock Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
        
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        $result = $this->adapter->compress($sourcePath, $targetPath);
        
        $this->assertEquals($targetPath, $result);
    }
    
    public function testCompressWithDefaultTargetPath(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');
        
        // Create a mock Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
        
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        $result = $this->adapter->compress($sourcePath);
        
        $this->assertEquals($sourcePath . '.gz', $result);
    }
    
    public function testCompressFailure(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');
        
        $targetPath = $this->tempDir . '/test_file.txt.gz';
        
        // Create a mock Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);
            
        $mockProcess->method('getErrorOutput')
            ->willReturn('Error: gzip failed');
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
            
        $this->mockFilesystem->expects($this->once())
            ->method('remove')
            ->with($targetPath);
        
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Failed to compress file');
        
        $this->adapter->compress($sourcePath, $targetPath);
    }
    
    public function testDecompress(): void
    {
        // Create a test compressed file
        $sourcePath = $this->tempDir . '/test_file.txt.gz';
        file_put_contents($sourcePath, 'compressed content');
        
        $targetPath = $this->tempDir . '/test_file.txt';
        
        // Create a mock Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
        
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        $result = $this->adapter->decompress($sourcePath, $targetPath);
        
        $this->assertEquals($targetPath, $result);
    }
    
    public function testDecompressWithDefaultTargetPath(): void
    {
        // Create a test compressed file
        $sourcePath = $this->tempDir . '/test_file.txt.gz';
        file_put_contents($sourcePath, 'compressed content');
        
        // Create a mock Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
        
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        $result = $this->adapter->decompress($sourcePath);
        
        $this->assertEquals($this->tempDir . '/test_file.txt', $result);
    }
    
    public function testDecompressFailure(): void
    {
        // Create a test compressed file
        $sourcePath = $this->tempDir . '/test_file.txt.gz';
        file_put_contents($sourcePath, 'compressed content');
        
        $targetPath = $this->tempDir . '/test_file.txt';
        
        // Create a mock Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);
            
        $mockProcess->method('getErrorOutput')
            ->willReturn('Error: gunzip failed');
        
        // Configure mock filesystem
        $this->mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
            
        $this->mockFilesystem->expects($this->once())
            ->method('remove')
            ->with($targetPath);
        
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Failed to decompress file');
        
        $this->adapter->decompress($sourcePath, $targetPath);
    }
    
    public function testSupports(): void
    {
        $this->assertTrue($this->adapter->supports($this->tempDir . '/test_file.txt.gz'));
        $this->assertFalse($this->adapter->supports($this->tempDir . '/test_file.txt'));
        $this->assertFalse($this->adapter->supports($this->tempDir . '/test_file.zip'));
    }
    
    public function testGetExtension(): void
    {
        $this->assertEquals('gz', $this->adapter->getExtension());
    }
    
    /**
     * Helper method to mock a static method
     */
    private function mockStaticMethod($class, $method, $callback)
    {
        $mock = \Closure::bind(function($class, $method, $callback) {
            $class::$method = $callback;
            return function() use($class, $method) {
                $class::$method = null;
            };
        }, null, $class);
        
        return $mock($class, $method, $callback);
    }
}