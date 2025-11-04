<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Compression;

use ProBackupBundle\Exception\BackupException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Adapter for zip compression.
 */
class ZipCompression implements CompressionAdapterInterface
{
    private readonly Filesystem $filesystem;

    /**
     * @var int Compression level (0-9)
     */
    private readonly int $compressionLevel;

    /**
     * Constructor.
     *
     * @param int  $compressionLevel Compression level (0-9, where 9 is highest)
     * @param bool $keepOriginal     Whether to keep the original file after compression
     */
    public function __construct(int $compressionLevel = 6, private readonly bool $keepOriginal = false, private readonly ?LoggerInterface $logger = new NullLogger())
    {
        $this->compressionLevel = max(0, min(9, $compressionLevel));
        $this->filesystem = new Filesystem();
    }

    public function compress(string $sourcePath, ?string $targetPath = null, array $options = []): string
    {
        $this->logger->info('Compressing file with zip', [
            'source' => $sourcePath,
            'target' => $targetPath,
            'level' => $options['level'] ?? $this->compressionLevel,
        ]);

        if (!$this->filesystem->exists($sourcePath)) {
            throw new BackupException(\sprintf('Source file not found: %s', $sourcePath));
        }

        // Determine target path if not provided
        if (null === $targetPath) {
            $targetPath = $sourcePath.'.'.$this->getExtension();
        }

        // Ensure target directory exists
        $targetDir = \dirname($targetPath);
        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir, 0755);
        }

        // Get compression level from options or use default
        $level = $options['level'] ?? $this->compressionLevel;
        $level = max(0, min(9, $level));

        // Determine whether to keep the original file
        $keepOriginal = $options['keep_original'] ?? $this->keepOriginal;

        try {
            // Use zip command line tool for compression
            if (is_dir($sourcePath)) {
                // Zip the contents of the directory (without adding the top-level folder)
                $sourceDir = $sourcePath;
                $sourceSpec = '.'; // zip current directory contents
                $recursiveFlag = ' -r';
            } else {
                // Zip a single file
                $sourceDir = \dirname($sourcePath);
                $sourceSpec = basename($sourcePath);
                $recursiveFlag = '';
            }

            // Change to the source directory to avoid full paths in the zip file
            $command = \sprintf(
                'cd %s && zip -%d%s %s %s',
                escapeshellarg($sourceDir),
                $level,
                $recursiveFlag,
                escapeshellarg($targetPath),
                escapeshellarg($sourceSpec)
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
            $this->logger->error('Zip compression failed', [
                'source' => $sourcePath,
                'target' => $targetPath,
                'exception' => $e->getMessage(),
            ]);

            // Clean up any partial target file
            if ($this->filesystem->exists($targetPath)) {
                $this->filesystem->remove($targetPath);
            }

            throw new BackupException(\sprintf('Failed to compress file: %s', $e->getMessage()), 0, $e);
        }
    }

    public function decompress(string $sourcePath, ?string $targetPath = null, array $options = []): string
    {
        $this->logger->info('Decompressing file with unzip', [
            'source' => $sourcePath,
            'target' => $targetPath,
        ]);

        if (!$this->filesystem->exists($sourcePath)) {
            throw new BackupException(\sprintf('Source file not found: %s', $sourcePath));
        }

        if (!$this->supports($sourcePath)) {
            throw new BackupException(\sprintf('File is not a zip file: %s', $sourcePath));
        }

        // Determine target directory if not provided
        $targetDir = null;
        $extractSingleFile = false;

        if (null === $targetPath) {
            // Extract to the same directory as the zip file
            $targetDir = \dirname($sourcePath);
            $extractSingleFile = false;
        } else {
            // Check if target path is a directory or a file
            if (str_ends_with($targetPath, '/') || is_dir($targetPath)) {
                // Target is a directory
                $targetDir = rtrim($targetPath, '/');
                $extractSingleFile = false;
            } else {
                // Target is a file, extract only the first file in the zip
                $targetDir = \dirname($targetPath);
                $extractSingleFile = true;
            }
        }

        // Ensure target directory exists
        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir, 0755);
        }

        // Determine whether to keep the original file
        $keepOriginal = $options['keep_original'] ?? $this->keepOriginal;

        try {
            if ($extractSingleFile) {
                // List the contents of the zip file
                $listCommand = \sprintf('unzip -l %s', escapeshellarg($sourcePath));
                $listProcess = Process::fromShellCommandline($listCommand);
                $listProcess->run();

                if (!$listProcess->isSuccessful()) {
                    throw new ProcessFailedException($listProcess);
                }

                // Parse the output to get the first file
                $output = $listProcess->getOutput();
                preg_match('/\s+\d+\s+[\d-]+\s+[\d:]+\s+(.+)/', $output, $matches);

                if (empty($matches[1])) {
                    throw new BackupException('No files found in zip archive');
                }

                $firstFile = trim($matches[1]);

                // Extract only the first file and rename it
                $command = \sprintf(
                    'unzip -j -o %s %s -d %s && mv %s %s',
                    escapeshellarg($sourcePath),
                    escapeshellarg($firstFile),
                    escapeshellarg($targetDir),
                    escapeshellarg($targetDir.'/'.basename($firstFile)),
                    escapeshellarg((string) $targetPath)
                );
            } else {
                // Extract all files to the target directory
                $command = \sprintf(
                    'unzip -o %s -d %s',
                    escapeshellarg($sourcePath),
                    escapeshellarg($targetDir)
                );
            }

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

            // Return the path to the extracted file or directory
            if ($extractSingleFile) {
                return $targetPath;
            } else {
                // Get the list of extracted files
                $files = glob($targetDir.'/*');

                // If there's only one file and it's not a directory, return it
                if (1 === \count($files) && !is_dir($files[0])) {
                    return $files[0];
                }

                // Otherwise return the target directory
                return $targetDir;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Zip decompression failed', [
                'source' => $sourcePath,
                'target' => $targetPath ?? $targetDir,
                'exception' => $e->getMessage(),
            ]);

            throw new BackupException(\sprintf('Failed to decompress file: %s', $e->getMessage()), 0, $e);
        }
    }

    public function supports(string $filePath): bool
    {
        // Check file extension
        if (pathinfo($filePath, \PATHINFO_EXTENSION) === $this->getExtension()) {
            return true;
        }

        // If the file exists, check its content (magic bytes)
        if ($this->filesystem->exists($filePath)) {
            $handle = fopen($filePath, 'r');
            if ($handle) {
                $header = fread($handle, 4);
                fclose($handle);

                // Zip files start with the magic bytes 'PK\x03\x04'
                return '504b0304' === bin2hex($header);
            }
        }

        return false;
    }

    public function getExtension(): string
    {
        return 'zip';
    }
}
