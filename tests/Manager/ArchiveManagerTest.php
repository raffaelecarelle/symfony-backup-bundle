<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Manager;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Compression\CompressionAdapterInterface;
use ProBackupBundle\Exception\BackupException;
use ProBackupBundle\Manager\ArchiveManager;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Unit tests for ArchiveManager.
 */
class ArchiveManagerTest extends TestCase
{
    private ArchiveManager $archiveManager;
    private Filesystem $filesystem;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveManager = new ArchiveManager();
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/archive_manager_test_'.uniqid('', true);
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }

        parent::tearDown();
    }

    public function testAddCompressionAdapter(): void
    {
        $mockAdapter = $this->createMock(CompressionAdapterInterface::class);

        $result = $this->archiveManager->addCompressionAdapter('test', $mockAdapter);

        $this->assertInstanceOf(ArchiveManager::class, $result, 'Should return self for fluent interface');
    }

    public function testCompressFileWithZipNoRemoveSource(): void
    {
        // Create test file
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content for zip compression');

        $targetPath = $this->tempDir.'/test.zip';

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockZipAdapter->expects($this->once())
            ->method('compress')
            ->with($sourceFile, $targetPath, ['keep_original' => true]);

        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Compress
        $result = $this->archiveManager->compress($sourceFile, $targetPath, 'zip', false);

        $this->assertEquals($targetPath, $result);
        $this->assertTrue($this->filesystem->exists($sourceFile), 'Source file should still exist');
    }

    public function testCompressFileWithZipRemoveSource(): void
    {
        // Create test file
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content for zip compression');

        $targetPath = $this->tempDir.'/test.zip';

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockZipAdapter->expects($this->once())
            ->method('compress')
            ->with($sourceFile, $targetPath, ['keep_original' => false]);

        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Compress with remove source
        $result = $this->archiveManager->compress($sourceFile, $targetPath, 'zip', true);

        $this->assertEquals($targetPath, $result);
    }

    public function testCompressFileWithGzip(): void
    {
        // Create test file
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content for gzip compression');

        // Mock gzip adapter
        $mockGzipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockGzipAdapter->expects($this->once())
            ->method('compress')
            ->with($sourceFile, null, ['keep_original' => true]);

        $this->archiveManager->addCompressionAdapter('gzip', $mockGzipAdapter);

        // Compress
        $result = $this->archiveManager->compress($sourceFile, $sourceFile.'.gz', 'gzip', false);

        $this->assertEquals($sourceFile.'.gz', $result);
    }

    public function testCompressDirectoryWithGzip(): void
    {
        // Create test directory with files
        $sourceDir = $this->tempDir.'/test_dir';
        $this->filesystem->mkdir($sourceDir);
        file_put_contents($sourceDir.'/file1.txt', 'Content 1');
        file_put_contents($sourceDir.'/file2.txt', 'Content 2');

        $targetPath = $this->tempDir.'/test_dir.tar.gz';

        // Mock gzip adapter
        $mockGzipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockGzipAdapter->expects($this->once())
            ->method('compress')
            ->with(
                $this->stringEndsWith('.tar'),
                $targetPath,
                ['keep_original' => false]
            );

        $this->archiveManager->addCompressionAdapter('gzip', $mockGzipAdapter);

        // Compress directory
        $result = $this->archiveManager->compress($sourceDir, $targetPath, 'gzip', false);

        $this->assertEquals($targetPath, $result);
    }

    public function testCompressDirectoryWithGzipRemoveSource(): void
    {
        // Create test directory with files
        $sourceDir = $this->tempDir.'/test_dir_remove';
        $this->filesystem->mkdir($sourceDir);
        file_put_contents($sourceDir.'/file1.txt', 'Content 1');
        file_put_contents($sourceDir.'/file2.txt', 'Content 2');

        $targetPath = $this->tempDir.'/test_dir_remove.tar.gz';

        // Mock gzip adapter that simulates successful compression
        $mockGzipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockGzipAdapter->expects($this->once())
            ->method('compress')
            ->willReturnCallback(function ($source, $target) {
                // Simulate creating the compressed file
                touch($target);

                return $target;
            });

        $this->archiveManager->addCompressionAdapter('gzip', $mockGzipAdapter);

        // Compress directory with remove source
        $result = $this->archiveManager->compress($sourceDir, $targetPath, 'gzip', true);

        $this->assertEquals($targetPath, $result);
        $this->assertFalse($this->filesystem->exists($sourceDir), 'Source directory should be removed');
    }

    public function testCompressWithUnsupportedType(): void
    {
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Unsupported compression type: rar');

        $this->archiveManager->compress($sourceFile, $this->tempDir.'/test.rar', 'rar', false);
    }

    public function testCompressWithoutZipAdapter(): void
    {
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Zip compression adapter not available');

        $this->archiveManager->compress($sourceFile, $this->tempDir.'/test.zip', 'zip', false);
    }

    public function testCompressWithoutGzipAdapter(): void
    {
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Gzip compression adapter not available');

        $this->archiveManager->compress($sourceFile, $this->tempDir.'/test.gz', 'gzip', false);
    }

    public function testDecompressZipFile(): void
    {
        $archivePath = $this->tempDir.'/test.zip';
        $targetPath = $this->tempDir.'/extracted';

        // Create mock archive file
        touch($archivePath);

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockZipAdapter->expects($this->once())
            ->method('decompress')
            ->with($archivePath, $targetPath, ['keep_original' => true]);

        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Decompress
        $result = $this->archiveManager->decompress($archivePath, $targetPath, true);

        $this->assertEquals($targetPath, $result);
    }

    public function testDecompressZipFileWithoutKeepOriginal(): void
    {
        $archivePath = $this->tempDir.'/test.zip';
        $targetPath = $this->tempDir.'/extracted';

        // Create mock archive file
        touch($archivePath);

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockZipAdapter->expects($this->once())
            ->method('decompress')
            ->with($archivePath, $targetPath, ['keep_original' => false]);

        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Decompress without keeping original
        $result = $this->archiveManager->decompress($archivePath, $targetPath, false);

        $this->assertEquals($targetPath, $result);
    }

    public function testDecompressGzipFile(): void
    {
        $archivePath = $this->tempDir.'/test.sql.gz';
        $targetPath = $this->tempDir.'/test.sql';

        // Create mock archive file
        touch($archivePath);

        // Mock gzip adapter
        $mockGzipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockGzipAdapter->expects($this->once())
            ->method('decompress')
            ->with($archivePath, $targetPath, ['keep_original' => true])
            ->willReturn($targetPath);

        $this->archiveManager->addCompressionAdapter('gzip', $mockGzipAdapter);

        // Decompress
        $result = $this->archiveManager->decompress($archivePath, $targetPath, true);

        $this->assertEquals($targetPath, $result);
    }

    public function testDecompressTarGzFile(): void
    {
        $archivePath = $this->tempDir.'/test.tar.gz';

        // Create a real tar.gz file for testing
        $testDir = $this->tempDir.'/test_content';
        $this->filesystem->mkdir($testDir);
        file_put_contents($testDir.'/file1.txt', 'Content 1');
        file_put_contents($testDir.'/file2.txt', 'Content 2');

        // Create tar file
        $tarPath = $this->tempDir.'/test.tar';
        $tarCmd = sprintf('tar -cf %s -C %s .', escapeshellarg($tarPath), escapeshellarg($testDir));
        exec($tarCmd);

        // Create gz file from tar
        $gzCmd = sprintf('gzip -c %s > %s', escapeshellarg($tarPath), escapeshellarg($archivePath));
        exec($gzCmd);

        // Clean up tar file
        unlink($tarPath);

        // Mock gzip adapter
        $mockGzipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockGzipAdapter->expects($this->once())
            ->method('decompress')
            ->willReturnCallback(function ($source, $target) {
                // Simulate decompressing the .gz file to .tar
                $gzCmd = sprintf('gzip -dc %s > %s', escapeshellarg($source), escapeshellarg($target));
                exec($gzCmd);

                return $target;
            });

        $this->archiveManager->addCompressionAdapter('gzip', $mockGzipAdapter);

        // Decompress (will create temp directory automatically)
        $result = $this->archiveManager->decompress($archivePath, null, true);

        $this->assertNotNull($result);
        $this->assertStringContainsString('extract_', $result, 'Should create temp extraction directory');
        $this->assertTrue($this->filesystem->exists($result.'/file1.txt'), 'Should extract file1.txt');
        $this->assertTrue($this->filesystem->exists($result.'/file2.txt'), 'Should extract file2.txt');
    }

    public function testDecompressWithoutZipAdapter(): void
    {
        $archivePath = $this->tempDir.'/test.zip';
        touch($archivePath);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Zip compression adapter not available for decompression');

        $this->archiveManager->decompress($archivePath, $this->tempDir.'/extracted');
    }

    public function testDecompressWithoutGzipAdapter(): void
    {
        $archivePath = $this->tempDir.'/test.gz';
        touch($archivePath);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Gzip compression adapter not available for decompression');

        $this->archiveManager->decompress($archivePath, $this->tempDir.'/test');
    }

    public function testDecompressUnknownFormat(): void
    {
        $archivePath = $this->tempDir.'/test.txt';
        touch($archivePath);

        // Should return original path for unknown formats
        $result = $this->archiveManager->decompress($archivePath, null);

        $this->assertEquals($archivePath, $result);
    }

    public function testDetectCompressionTypeZip(): void
    {
        $result = $this->archiveManager->detectCompressionType('/path/to/file.zip');
        $this->assertEquals('zip', $result);
    }

    public function testDetectCompressionTypeGzip(): void
    {
        $result = $this->archiveManager->detectCompressionType('/path/to/file.gz');
        $this->assertEquals('gzip', $result);

        $result = $this->archiveManager->detectCompressionType('/path/to/file.tar.gz');
        $this->assertEquals('gzip', $result);
    }

    public function testDetectCompressionTypeUnknown(): void
    {
        $result = $this->archiveManager->detectCompressionType('/path/to/file.txt');
        $this->assertNull($result);

        $result = $this->archiveManager->detectCompressionType('/path/to/file.bak');
        $this->assertNull($result);
    }

    public function testCompressCreatesOutputDirectory(): void
    {
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content');

        // Target path in non-existent directory
        $targetPath = $this->tempDir.'/nested/dir/test.zip';

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Compress - should create nested directory
        $this->archiveManager->compress($sourceFile, $targetPath, 'zip', false);

        $this->assertTrue(
            $this->filesystem->exists($this->tempDir.'/nested/dir'),
            'Should create output directory structure'
        );
    }

    public function testCompressCaseInsensitive(): void
    {
        $sourceFile = $this->tempDir.'/test.txt';
        file_put_contents($sourceFile, 'Test content');

        $targetPath = $this->tempDir.'/test.zip';

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockZipAdapter->expects($this->once())
            ->method('compress');

        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Test with uppercase compression type
        $this->archiveManager->compress($sourceFile, $targetPath, 'ZIP', false);
    }

    public function testDecompressCaseInsensitive(): void
    {
        $archivePath = $this->tempDir.'/test.ZIP';
        touch($archivePath);

        // Mock zip adapter
        $mockZipAdapter = $this->createMock(CompressionAdapterInterface::class);
        $mockZipAdapter->expects($this->once())
            ->method('decompress');

        $this->archiveManager->addCompressionAdapter('zip', $mockZipAdapter);

        // Test with uppercase extension
        $this->archiveManager->decompress($archivePath, $this->tempDir.'/extracted');
    }
}
