<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Exception\BackupException;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Adapter for SQLite database backups.
 */
class SQLiteAdapter implements BackupAdapterInterface
{
    private readonly LoggerInterface $logger;

    private readonly Filesystem $filesystem;

    /**
     * Constructor.
     */
    public function __construct(private readonly Connection $connection, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->filesystem = new Filesystem();
    }

    public function backup(BackupConfiguration $config): BackupResult
    {
        $startTime = microtime(true);
        $filename = $this->generateFilename($config);
        $outputPath = $config->getOutputPath();
        $filepath = $outputPath.'/'.$filename;

        // Ensure the output directory exists
        if (!$this->filesystem->exists($outputPath)) {
            $this->filesystem->mkdir($outputPath, 0755);
        }

        $this->logger->info('Starting SQLite backup', [
            'database' => $this->getDatabasePath(),
            'output' => $filepath,
        ]);

        try {
            // For SQLite, we can simply copy the database file
            $dbPath = $this->getDatabasePath();

            if (!$this->filesystem->exists($dbPath)) {
                throw new BackupException(\sprintf('SQLite database file not found: %s', $dbPath));
            }

            // Copy the database file
            $this->filesystem->copy($dbPath, $filepath);

            // Apply compression if needed
            $compressedPath = $this->compressIfNeeded($filepath, $config);
            $finalPath = $compressedPath ?: $filepath;

            $this->logger->info('SQLite backup completed', [
                'file' => $finalPath,
                'size' => filesize($finalPath),
                'duration' => microtime(true) - $startTime,
            ]);

            return new BackupResult(
                true,
                $finalPath,
                filesize($finalPath),
                new \DateTimeImmutable(),
                microtime(true) - $startTime
            );
        } catch (\Throwable $e) {
            $this->logger->error('SQLite backup failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial files
            if ($this->filesystem->exists($filepath)) {
                $this->filesystem->remove($filepath);
            }

            return new BackupResult(
                false,
                null,
                null,
                new \DateTimeImmutable(),
                microtime(true) - $startTime,
                $e->getMessage()
            );
        }
    }

    public function restore(string $backupPath, array $options = []): bool
    {
        $this->logger->info('Starting SQLite restore', [
            'database' => $this->getDatabasePath(),
            'file' => $backupPath,
        ]);

        try {
            // Decompress if needed
            $decompressedPath = $this->decompressIfNeeded($backupPath);
            $finalPath = $decompressedPath ?: $backupPath;

            $dbPath = $this->getDatabasePath();

            // Check if the database file exists and is writable
            if ($this->filesystem->exists($dbPath)) {
                if (!is_writable($dbPath)) {
                    throw new BackupException(\sprintf('SQLite database file is not writable: %s', $dbPath));
                }

                // Create a backup of the current database if requested
                if ($options['backup_existing'] ?? true) {
                    $backupExistingPath = $dbPath.'.bak.'.date('YmdHis');
                    $this->filesystem->copy($dbPath, $backupExistingPath);
                    $this->logger->info('Created backup of existing database', [
                        'backup_path' => $backupExistingPath,
                    ]);
                }
            }

            // Copy the backup file to the database location
            $this->filesystem->copy($finalPath, $dbPath, true);

            // Clean up temporary decompressed file
            if ($decompressedPath && $decompressedPath !== $backupPath) {
                $this->filesystem->remove($decompressedPath);
            }

            $this->logger->info('SQLite restore completed', [
                'database' => $dbPath,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('SQLite restore failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up temporary decompressed file
            if (isset($decompressedPath) && $decompressedPath !== $backupPath && $this->filesystem->exists($decompressedPath)) {
                $this->filesystem->remove($decompressedPath);
            }

            return false;
        }
    }

    public function supports(string $type): bool
    {
        return 'sqlite' === $type || 'database' === $type;
    }

    public function validate(BackupConfiguration $config): array
    {
        $errors = [];

        if (!$this->connection->isConnected()) {
            $errors[] = 'Database connection is not established';
        }

        $dbPath = $this->getDatabasePath();
        if (!$this->filesystem->exists($dbPath)) {
            $errors[] = \sprintf('SQLite database file not found: %s', $dbPath);
        }

        if (!$config->getOutputPath()) {
            $errors[] = 'Output path is not specified';
        }

        return $errors;
    }

    /**
     * Get the path to the SQLite database file.
     */
    private function getDatabasePath(): string
    {
        $params = $this->connection->getParams();

        if (isset($params['path'])) {
            return $params['path'];
        }

        if (isset($params['url'])) {
            $url = parse_url($params['url']);
            if (isset($url['path'])) {
                return $url['path'];
            }
        }

        throw new BackupException('Could not determine SQLite database path');
    }

    /**
     * Generate a filename for the backup.
     */
    private function generateFilename(BackupConfiguration $config): string
    {
        $dbPath = $this->getDatabasePath();
        $dbName = pathinfo($dbPath, \PATHINFO_FILENAME);
        $timestamp = date('Y-m-d_H-i-s');
        $name = $config->getName() ?: 'backup';

        return \sprintf('%s_%s_%s.sqlite', $dbName, $name, $timestamp);
    }

    /**
     * Compress the backup file if compression is enabled.
     *
     * @return string|null Path to the compressed file, or null if no compression
     */
    private function compressIfNeeded(string $filepath, BackupConfiguration $config): ?string
    {
        $compression = $config->getCompression();
        if (!$compression) {
            return null;
        }

        $this->logger->info('Compressing backup file', [
            'file' => $filepath,
            'compression' => $compression,
        ]);

        if ('gzip' === $compression) {
            $compressedPath = $filepath.'.gz';

            $process = Process::fromShellCommandline(\sprintf('gzip -c %s > %s', escapeshellarg($filepath), escapeshellarg($compressedPath)));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Remove the original file
            $this->filesystem->remove($filepath);

            return $compressedPath;
        }

        // Other compression types can be added here

        return null;
    }

    /**
     * Decompress the backup file if it's compressed.
     *
     * @return string|null Path to the decompressed file, or null if no decompression
     */
    private function decompressIfNeeded(string $filepath): ?string
    {
        $extension = pathinfo($filepath, \PATHINFO_EXTENSION);

        if ('gz' === $extension) {
            $this->logger->info('Decompressing gzip backup file', [
                'file' => $filepath,
            ]);

            $decompressedPath = substr($filepath, 0, -3); // Remove .gz

            $process = Process::fromShellCommandline(\sprintf('gunzip -c %s > %s', escapeshellarg($filepath), escapeshellarg($decompressedPath)));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return $decompressedPath;
        }

        // Other compression types can be added here

        return null;
    }
}
