<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Compression;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Compression\ZipCompression;
use ProBackupBundle\Exception\BackupException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ZipCompressionTest extends TestCase
{
    private string $tempDir;

    private ZipCompression $adapter;

    private MockObject $mockLogger;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/zip_compression_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->filesystem = new Filesystem();

        // Create adapter with real filesystem for integration testing
        $this->adapter = new ZipCompression(6, true, $this->mockLogger);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
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

    private function createValidZipFile(string $filePath, string $content): void
    {
        // Create a valid zip file using the zip command
        $tempFile = $this->tempDir . '/temp_content.txt';
        file_put_contents($tempFile, $content);

        $process = Process::fromShellCommandline(\sprintf(
            'cd %s && zip -6 %s %s',
            escapeshellarg($this->tempDir),
            escapeshellarg(basename($filePath)),
            escapeshellarg(basename($tempFile))
        ));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to create valid zip file for testing: ' . $process->getErrorOutput());
        }

        // Remove the temporary file
        unlink($tempFile);
    }

    public function testCompress(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');

        $targetPath = $this->tempDir . '/test_file.txt.zip';

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

        $this->assertEquals($sourcePath . '.zip', $result);
        $this->assertTrue(file_exists($sourcePath . '.zip'));
    }

    public function testCompressWithCustomCompressionLevel(): void
    {
        // Create a test file
        $sourcePath = $this->tempDir . '/test_file.txt';
        file_put_contents($sourcePath, 'test content');

        $targetPath = $this->tempDir . '/test_file.txt.zip';

        $result = $this->adapter->compress($sourcePath, $targetPath, ['level' => 9]);

        $this->assertEquals($targetPath, $result);
        $this->assertTrue(file_exists($targetPath));
    }

    public function testCompressFailure(): void
    {
        // Use a non-existent source file
        $sourcePath = $this->tempDir . '/non_existent_file.txt';
        $targetPath = $this->tempDir . '/test_file.txt.zip';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Source file not found');

        $this->adapter->compress($sourcePath, $targetPath);
    }

    public function testDecompress(): void
    {
        // Create a valid zip file
        $sourcePath = $this->tempDir . '/test_file.txt.zip';
        $originalContent = 'test content for decompression';

        // Create a file to zip
        $contentFile = $this->tempDir . '/test_file.txt';
        file_put_contents($contentFile, $originalContent);

        // Create the zip file
        $this->createValidZipFile($sourcePath, $originalContent);

        // Remove the original file to ensure we're testing decompression
        unlink($contentFile);

        $targetPath = $this->tempDir . '/extracted_file.txt';

        $result = $this->adapter->decompress($sourcePath, $targetPath);

        $this->assertEquals($targetPath, $result);
        $this->assertTrue(file_exists($targetPath));
        $this->assertEquals($originalContent, file_get_contents($targetPath));
    }

    public function testDecompressToDirectory(): void
    {
        // Create a valid zip file
        $sourcePath = $this->tempDir . '/test_file.txt.zip';
        $originalContent = 'test content for decompression to directory';

        // Create a file to zip
        $contentFile = $this->tempDir . '/test_file.txt';
        file_put_contents($contentFile, $originalContent);

        // Create the zip file
        $this->createValidZipFile($sourcePath, $originalContent);

        // Remove the original file to ensure we're testing decompression
        unlink($contentFile);

        $targetDir = $this->tempDir . '/extracted/';
        $this->filesystem->mkdir($targetDir);

        $result = $this->adapter->decompress($sourcePath, $targetDir);

        // Should return the path to the extracted file since there's only one file
        $this->assertEquals($targetDir . 'temp_content.txt', $result);
        $this->assertTrue(file_exists($targetDir . 'temp_content.txt'));
        $this->assertEquals($originalContent, file_get_contents($targetDir . 'temp_content.txt'));
    }

    public function testDecompressWithDefaultTargetPath(): void
    {
        // Create a valid zip file
        $sourcePath = $this->tempDir . '/test_file.txt.zip';
        $originalContent = 'test content for decompression with default path';

        // Create a file to zip
        $contentFile = $this->tempDir . '/test_file.txt';
        file_put_contents($contentFile, $originalContent);

        // Create the zip file
        $this->createValidZipFile($sourcePath, $originalContent);

        // Remove the original file to ensure we're testing decompression
        unlink($contentFile);

        $result = $this->adapter->decompress($sourcePath);

        // Should return the path to the extracted file or directory
        $this->assertTrue(file_exists($result));

        // If result is a directory, look for the extracted file inside it
        if (is_dir($result)) {
            $files = glob($result . '/*');
            $this->assertNotEmpty($files, 'No files found in extracted directory');

            // Find the first non-directory file
            $extractedFile = null;
            foreach ($files as $file) {
                if (!is_dir($file)) {
                    $extractedFile = $file;
                    break;
                }
            }

            $this->assertNotNull($extractedFile, 'No files found in extracted directory');
            $this->assertEquals($originalContent, file_get_contents($extractedFile));
        } else {
            // Result is a file
            $this->assertEquals($originalContent, file_get_contents($result));
        }
    }

    public function testDecompressFailure(): void
    {
        // Use a non-existent source file
        $sourcePath = $this->tempDir . '/non_existent_file.txt.zip';
        $targetPath = $this->tempDir . '/test_file.txt';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Source file not found');

        $this->adapter->decompress($sourcePath, $targetPath);
    }

    public function testDecompressInvalidZipFile(): void
    {
        // Create an invalid zip file
        $sourcePath = $this->tempDir . '/invalid_file.txt.zip';
        file_put_contents($sourcePath, 'this is not a valid zip file');

        $targetPath = $this->tempDir . '/test_file.txt';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Failed to decompress file');

        $this->adapter->decompress($sourcePath, $targetPath);
    }

    public function testSupports(): void
    {
        // Test with extension
        $this->assertTrue($this->adapter->supports($this->tempDir . '/test_file.txt.zip'));
        $this->assertFalse($this->adapter->supports($this->tempDir . '/test_file.txt'));
        $this->assertFalse($this->adapter->supports($this->tempDir . '/test_file.gz'));

        // Test with actual zip file
        $zipFile = $this->tempDir . '/real_zip_file.zip';
        $contentFile = $this->tempDir . '/content.txt';
        file_put_contents($contentFile, 'test content');
        $this->createValidZipFile($zipFile, 'test content');
        $this->assertTrue($this->adapter->supports($zipFile));

        // Test with file that has .zip extension but invalid content
        $fakeZipFile = $this->tempDir . '/fake_zip_file.zip';
        file_put_contents($fakeZipFile, 'not zip content');
        $this->assertTrue($this->adapter->supports($fakeZipFile)); // Should return true based on extension
    }

    public function testGetExtension(): void
    {
        $this->assertEquals('zip', $this->adapter->getExtension());
    }
}
