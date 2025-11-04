<?php

declare(strict_types=1);

namespace ProBackupBundle\Manager;

use ProBackupBundle\Adapter\Compression\CompressionAdapterInterface;
use ProBackupBundle\Exception\BackupException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Centralizes archive compression and decompression operations.
 */
class ArchiveManager
{
    /**
     * @var array<string, CompressionAdapterInterface> Map of compression adapters
     */
    private array $compressionAdapters = [];

    private readonly Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Add a compression adapter.
     */
    public function addCompressionAdapter(string $name, CompressionAdapterInterface $adapter): self
    {
        $this->compressionAdapters[$name] = $adapter;

        return $this;
    }

    /**
     * Compress a source (file or directory) into a target archive.
     *
     * @param string $source          Path to source file or directory
     * @param string $targetPath      Path where the archive should be created
     * @param string $compressionType Type of compression (zip, gzip)
     * @param bool   $removeSource    Whether to remove the source after compression
     *
     * @return string Path to the created archive
     *
     * @throws BackupException If compression fails or adapter is not available
     */
    public function compress(string $source, string $targetPath, string $compressionType, bool $removeSource = false): string
    {
        // Ensure output directory exists
        if (!$this->filesystem->exists(\dirname($targetPath))) {
            $this->filesystem->mkdir(\dirname($targetPath), 0755);
        }

        $compressionType = strtolower($compressionType);

        if ('zip' === $compressionType) {
            $zip = $this->compressionAdapters['zip'] ?? null;
            if (!$zip) {
                throw new BackupException('Zip compression adapter not available');
            }
            $zip->compress($source, $targetPath, ['keep_original' => !$removeSource]);

            return $targetPath;
        }

        if ('gzip' === $compressionType) {
            $gzip = $this->compressionAdapters['gzip'] ?? null;
            if (!$gzip) {
                throw new BackupException('Gzip compression adapter not available');
            }

            // For directories, we need to tar first, then gzip
            if (is_dir($source)) {
                // Ensure targetPath ends with .tar.gz
                if (!str_ends_with($targetPath, '.tar.gz')) {
                    $targetPath .= '.tar.gz';
                }

                $tarPath = preg_replace('/\.gz$/', '', $targetPath) ?: ($targetPath.'.tar');

                // Create tar from source directory contents (without top-level folder)
                $tarCmd = \sprintf('tar -cf %s -C %s .', escapeshellarg($tarPath), escapeshellarg($source));
                $proc = \Symfony\Component\Process\Process::fromShellCommandline($tarCmd);
                $proc->setTimeout(3600);
                $proc->run();
                if (!$proc->isSuccessful()) {
                    throw new \Symfony\Component\Process\Exception\ProcessFailedException($proc);
                }

                try {
                    $gzip->compress($tarPath, $targetPath, ['keep_original' => false]);
                } finally {
                    if ($this->filesystem->exists($tarPath)) {
                        $this->filesystem->remove($tarPath);
                    }
                }

                // Remove source if requested
                if ($removeSource && $this->filesystem->exists($source)) {
                    $this->filesystem->remove($source);
                }

                return $targetPath;
            }

            // For files, directly gzip
            $gzip->compress($source, null, ['keep_original' => !$removeSource]);

            return $source.'.gz';
        }

        throw new BackupException(\sprintf('Unsupported compression type: %s', $compressionType));
    }

    /**
     * Decompress an archive into a target location.
     *
     * @param string      $archivePath  Path to the archive
     * @param string|null $targetPath   Target directory or file path (null for same directory)
     * @param bool        $keepOriginal Whether to keep the original archive
     *
     * @return string Path to the decompressed content (file or directory)
     *
     * @throws BackupException If decompression fails or adapter is not available
     */
    public function decompress(string $archivePath, ?string $targetPath = null, bool $keepOriginal = true): string
    {
        $extension = pathinfo($archivePath, \PATHINFO_EXTENSION);

        if ('zip' === strtolower($extension)) {
            $zip = $this->compressionAdapters['zip'] ?? null;
            if (!$zip) {
                throw new BackupException('Zip compression adapter not available for decompression');
            }

            // If targetPath is not provided, create a temp directory
            if (null === $targetPath) {
                $targetPath = sys_get_temp_dir().'/extract_'.uniqid('', true);
                $this->filesystem->mkdir($targetPath, 0755);
            }

            // Extract into the target directory, keep original archive based on flag
            $zip->decompress($archivePath, $targetPath, ['keep_original' => $keepOriginal]);

            return $targetPath;
        }

        if ('gz' === strtolower($extension)) {
            $gzip = $this->compressionAdapters['gzip'] ?? null;
            if (!$gzip) {
                throw new BackupException('Gzip compression adapter not available for decompression');
            }

            // Check if this is a .tar.gz archive
            if (str_ends_with($archivePath, '.tar.gz')) {
                // Create temp directory for extraction if not provided
                if (null === $targetPath) {
                    $targetPath = sys_get_temp_dir().'/extract_'.uniqid('', true);
                    $this->filesystem->mkdir($targetPath, 0755);
                }

                $tarPath = preg_replace('/\.gz$/', '', $archivePath) ?: ($archivePath.'.tar');
                try {
                    $gzip->decompress($archivePath, $tarPath, ['keep_original' => $keepOriginal]);

                    // Extract tar into the target directory
                    $tarCmd = \sprintf('tar -xf %s -C %s', escapeshellarg($tarPath), escapeshellarg($targetPath));
                    $proc = \Symfony\Component\Process\Process::fromShellCommandline($tarCmd);
                    $proc->setTimeout(3600);
                    $proc->run();
                    if (!$proc->isSuccessful()) {
                        throw new \Symfony\Component\Process\Exception\ProcessFailedException($proc);
                    }
                } finally {
                    if ($this->filesystem->exists($tarPath)) {
                        $this->filesystem->remove($tarPath);
                    }
                }

                return $targetPath;
            }

            // Plain .gz file (not tar.gz)
            $decompressedPath = $gzip->decompress($archivePath, $targetPath, ['keep_original' => $keepOriginal]);

            return $decompressedPath;
        }

        return $archivePath;
    }

    /**
     * Determine compression type from file extension.
     *
     * @param string $filePath Path to the file
     *
     * @return string|null Compression type (zip, gzip) or null if unknown
     */
    public function detectCompressionType(string $filePath): ?string
    {
        $extension = pathinfo($filePath, \PATHINFO_EXTENSION);

        return match (strtolower($extension)) {
            'zip' => 'zip',
            'gz' => 'gzip',
            default => null,
        };
    }
}
