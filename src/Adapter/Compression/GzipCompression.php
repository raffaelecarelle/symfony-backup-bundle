<?php

namespace Symfony\Component\Backup\Adapter\Compression;

use Symfony\Component\Backup\Exception\BackupException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Adapter for gzip compression.
 */
class GzipCompression implements CompressionAdapterInterface
{
    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;
    
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    
    /**
     * @var int Compression level (1-9)
     */
    private int $compressionLevel;
    
    /**
     * @var bool Whether to keep the original file after compression
     */
    private bool $keepOriginal;

    /**
     * Constructor.
     *
     * @param int $compressionLevel Compression level (1-9, where 9 is highest)
     * @param bool $keepOriginal Whether to keep the original file after compression
     * @param LoggerInterface|null $logger
     */
    public function __construct(int $compressionLevel = 6, bool $keepOriginal = false, ?LoggerInterface $logger = null)
    {
        $this->compressionLevel = max(1, min(9, $compressionLevel));
        $this->keepOriginal = $keepOriginal;
        $this->logger = $logger ?? new NullLogger();
        $this->filesystem = new Filesystem();
    }

    /**
     * {@inheritdoc}
     */
    public function compress(string $sourcePath, ?string $targetPath = null, array $options = []): string
    {
        $this->logger->info('Compressing file with gzip', [
            'source' => $sourcePath,
            'target' => $targetPath,
            'level' => $options['level'] ?? $this->compressionLevel,
        ]);
        
        if (!$this->filesystem->exists($sourcePath)) {
            throw new BackupException(sprintf('Source file not found: %s', $sourcePath));
        }
        
        // Determine target path if not provided
        if ($targetPath === null) {
            $targetPath = $sourcePath . '.' . $this->getExtension();
        }
        
        // Ensure target directory exists
        $targetDir = dirname($targetPath);
        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir, 0755);
        }
        
        // Get compression level from options or use default
        $level = $options['level'] ?? $this->compressionLevel;
        $level = max(1, min(9, $level));
        
        // Determine whether to keep the original file
        $keepOriginal = $options['keep_original'] ?? $this->keepOriginal;
        
        try {
            // Use gzip command line tool for compression
            $command = sprintf(
                'gzip -%d -c %s > %s',
                $level,
                escapeshellarg($sourcePath),
                escapeshellarg($targetPath)
            );
            
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            // Remove the original file if not keeping it
            if (!$keepOriginal) {
                $this->filesystem->remove($sourcePath);
            }
            
            return $targetPath;
        } catch (\Throwable $e) {
            $this->logger->error('Gzip compression failed', [
                'source' => $sourcePath,
                'target' => $targetPath,
                'exception' => $e->getMessage(),
            ]);
            
            // Clean up any partial target file
            if ($this->filesystem->exists($targetPath)) {
                $this->filesystem->remove($targetPath);
            }
            
            throw new BackupException(sprintf('Failed to compress file: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function decompress(string $sourcePath, ?string $targetPath = null, array $options = []): string
    {
        $this->logger->info('Decompressing file with gzip', [
            'source' => $sourcePath,
            'target' => $targetPath,
        ]);
        
        if (!$this->filesystem->exists($sourcePath)) {
            throw new BackupException(sprintf('Source file not found: %s', $sourcePath));
        }
        
        if (!$this->supports($sourcePath)) {
            throw new BackupException(sprintf('File is not a gzip file: %s', $sourcePath));
        }
        
        // Determine target path if not provided
        if ($targetPath === null) {
            // Remove .gz extension
            $targetPath = preg_replace('/\.gz$/', '', $sourcePath);
            
            // If the path didn't change, it didn't have a .gz extension
            if ($targetPath === $sourcePath) {
                $targetPath = $sourcePath . '.decompressed';
            }
        }
        
        // Ensure target directory exists
        $targetDir = dirname($targetPath);
        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir, 0755);
        }
        
        // Determine whether to keep the original file
        $keepOriginal = $options['keep_original'] ?? $this->keepOriginal;
        
        try {
            // Use gzip command line tool for decompression
            $command = sprintf(
                'gunzip -c %s > %s',
                escapeshellarg($sourcePath),
                escapeshellarg($targetPath)
            );
            
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            // Remove the original file if not keeping it
            if (!$keepOriginal) {
                $this->filesystem->remove($sourcePath);
            }
            
            return $targetPath;
        } catch (\Throwable $e) {
            $this->logger->error('Gzip decompression failed', [
                'source' => $sourcePath,
                'target' => $targetPath,
                'exception' => $e->getMessage(),
            ]);
            
            // Clean up any partial target file
            if ($this->filesystem->exists($targetPath)) {
                $this->filesystem->remove($targetPath);
            }
            
            throw new BackupException(sprintf('Failed to decompress file: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $filePath): bool
    {
        // Check file extension
        if (pathinfo($filePath, PATHINFO_EXTENSION) === $this->getExtension()) {
            return true;
        }
        
        // If the file exists, check its content (magic bytes)
        if ($this->filesystem->exists($filePath)) {
            $handle = fopen($filePath, 'rb');
            if ($handle) {
                $header = fread($handle, 2);
                fclose($handle);
                
                // Gzip files start with the magic bytes 0x1F 0x8B
                return bin2hex($header) === '1f8b';
            }
        }
        
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getExtension(): string
    {
        return 'gz';
    }
}