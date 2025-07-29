<?php

namespace Symfony\Component\Backup\Tests\Adapter\Compression;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Backup\Adapter\Compression\GzipCompression;
use Symfony\Component\Backup\Exception\BackupException;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

class GzipCompressionTest extends TestCase
{
    private $tempDir;
    private $adapter;
    private $mockLogger;
    private $filesystem; // Usa il filesystem reale invece di mock

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/gzip_compression_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->filesystem = new Filesystem(); // Filesystem reale

        // Create adapter with real filesystem for integration testing
        $this->adapter = new GzipCompression(6, true, $this->mockLogger);
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

    private function createValidGzipFile(string $filePath, string $content): void
    {
        // Crea un file gzip valido usando il comando gzip
        $tempFile = $filePath . '.tmp';
        file_put_contents($tempFile, $content);

        $process = Process::fromShellCommandline(sprintf(
            'gzip -c %s > %s',
            escapeshellarg($tempFile),
            escapeshellarg($filePath)
        ));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to create valid gzip file for testing');
        }

        // Rimuovi il file temporaneo
        unlink($tempFile);
    }

    public function testCompress(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');

        $targetPath = $this->tempDir . '/test_file.txt.gz';

        $result = $this->adapter->compress($sourcePath, $targetPath);

        $this->assertEquals($targetPath, $result);
        $this->assertTrue(file_exists($targetPath));
    }

    public function testCompressWithDefaultTargetPath(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');

        $result = $this->adapter->compress($sourcePath);

        $this->assertEquals($sourcePath . '.gz', $result);
        $this->assertTrue(file_exists($sourcePath . '.gz'));
    }

    public function testCompressFailure(): void
    {
        // Use a non-existent source file
        $sourcePath = $this->tempDir . '/non_existent_file.txt';
        $targetPath = $this->tempDir . '/test_file.txt.gz';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Source file not found');

        $this->adapter->compress($sourcePath, $targetPath);
    }

    public function testDecompress(): void
    {
        // Create a valid gzip file
        $sourcePath = $this->tempDir . '/test_file.txt.gz';
        $targetPath = $this->tempDir . '/test_file.txt';
        $originalContent = 'test content for decompression';

        $this->createValidGzipFile($sourcePath, $originalContent);

        $result = $this->adapter->decompress($sourcePath, $targetPath);

        $this->assertEquals($targetPath, $result);
        $this->assertTrue(file_exists($targetPath));
        $this->assertEquals($originalContent, file_get_contents($targetPath));
    }

    public function testDecompressWithDefaultTargetPath(): void
    {
        // Create a valid gzip file
        $sourcePath = $this->tempDir . '/test_file.txt.gz';
        $originalContent = 'test content for decompression with default path';

        $this->createValidGzipFile($sourcePath, $originalContent);

        $result = $this->adapter->decompress($sourcePath);

        $expectedTargetPath = $this->tempDir . '/test_file.txt';
        $this->assertEquals($expectedTargetPath, $result);
        $this->assertTrue(file_exists($expectedTargetPath));
        $this->assertEquals($originalContent, file_get_contents($expectedTargetPath));
    }

    public function testDecompressFailure(): void
    {
        // Use a non-existent source file
        $sourcePath = $this->tempDir . '/non_existent_file.txt.gz';
        $targetPath = $this->tempDir . '/test_file.txt';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Source file not found');

        $this->adapter->decompress($sourcePath, $targetPath);
    }

    public function testDecompressInvalidGzipFile(): void
    {
        // Create an invalid gzip file
        $sourcePath = $this->tempDir . '/invalid_file.txt.gz';
        file_put_contents($sourcePath, 'this is not a valid gzip file');

        $targetPath = $this->tempDir . '/test_file.txt';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Failed to decompress file');

        $this->adapter->decompress($sourcePath, $targetPath);
    }

    public function testSupports(): void
    {
        // Test with extension
        $this->assertTrue($this->adapter->supports($this->tempDir . '/test_file.txt.gz'));
        $this->assertFalse($this->adapter->supports($this->tempDir . '/test_file.txt'));
        $this->assertFalse($this->adapter->supports($this->tempDir . '/test_file.zip'));

        // Test with actual gzip file
        $gzipFile = $this->tempDir . '/real_gzip_file.gz';
        $this->createValidGzipFile($gzipFile, 'test content');
        $this->assertTrue($this->adapter->supports($gzipFile));

        // Test with file that has .gz extension but invalid content
        $fakeGzipFile = $this->tempDir . '/fake_gzip_file.gz';
        file_put_contents($fakeGzipFile, 'not gzip content');
        $this->assertTrue($this->adapter->supports($fakeGzipFile)); // Should return true based on extension
    }

    public function testGetExtension(): void
    {
        $this->assertEquals('gz', $this->adapter->getExtension());
    }
}