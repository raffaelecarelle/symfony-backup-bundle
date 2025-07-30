<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Adapter for SQL Server database backups.
 */
class SqlServerAdapter implements BackupAdapterInterface
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

        $this->logger->info('Starting SQL Server backup', [
            'database' => $this->connection->getDatabase(),
            'output' => $filepath,
        ]);

        try {
            // For SQL Server, we use T-SQL BACKUP DATABASE command
            $database = $this->connection->getDatabase();
            $backupFile = str_replace('\\', '\\\\', $filepath); // Escape backslashes for T-SQL

            $sql = \sprintf("BACKUP DATABASE [%s] TO DISK = N'%s' WITH NOFORMAT, NOINIT, NAME = N'%s-Full Database Backup', SKIP, NOREWIND, NOUNLOAD, STATS = 10",
                $database,
                $backupFile,
                $database
            );

            // Execute the backup command
            $this->connection->executeStatement($sql);

            // Apply compression if needed
            $compressedPath = $this->compressIfNeeded($filepath, $config);
            $finalPath = $compressedPath ?: $filepath;

            $this->logger->info('SQL Server backup completed', [
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
            $this->logger->error('SQL Server backup failed', [
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
        $this->logger->info('Starting SQL Server restore', [
            'database' => $this->connection->getDatabase(),
            'file' => $backupPath,
        ]);

        try {
            // Decompress if needed
            $decompressedPath = $this->decompressIfNeeded($backupPath);
            $finalPath = $decompressedPath ?: $backupPath;

            $database = $this->connection->getDatabase();
            $backupFile = str_replace('\\', '\\\\', $finalPath); // Escape backslashes for T-SQL

            // Set database to single user mode if requested
            if ($options['single_user'] ?? true) {
                $sql = \sprintf('ALTER DATABASE [%s] SET SINGLE_USER WITH ROLLBACK IMMEDIATE', $database);
                $this->connection->executeStatement($sql);
            }

            // Restore the database
            $sql = \sprintf("RESTORE DATABASE [%s] FROM DISK = N'%s' WITH FILE = 1, NOUNLOAD, REPLACE, STATS = 5",
                $database,
                $backupFile
            );

            // Add recovery options if specified
            if (isset($options['recovery']) && 'norecovery' === $options['recovery']) {
                $sql .= ', NORECOVERY';
            } else {
                $sql .= ', RECOVERY';
            }

            // Execute the restore command
            $this->connection->executeStatement($sql);

            // Set database back to multi user mode if it was set to single user
            if ($options['single_user'] ?? true) {
                $sql = \sprintf('ALTER DATABASE [%s] SET MULTI_USER', $database);
                $this->connection->executeStatement($sql);
            }

            // Clean up temporary decompressed file
            if ($decompressedPath && $decompressedPath !== $backupPath) {
                $this->filesystem->remove($decompressedPath);
            }

            $this->logger->info('SQL Server restore completed', [
                'database' => $database,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('SQL Server restore failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Try to set database back to multi user mode if it was set to single user
            if ($options['single_user'] ?? true) {
                try {
                    $sql = \sprintf('ALTER DATABASE [%s] SET MULTI_USER', $this->connection->getDatabase());
                    $this->connection->executeStatement($sql);
                } catch (\Throwable) {
                    // Ignore errors when trying to set multi user mode after failure
                }
            }

            // Clean up temporary decompressed file
            if (isset($decompressedPath) && $decompressedPath !== $backupPath && $this->filesystem->exists($decompressedPath)) {
                $this->filesystem->remove($decompressedPath);
            }

            return false;
        }
    }

    public function supports(string $type): bool
    {
        return 'sqlserver' === $type || 'mssql' === $type || 'database' === $type;
    }

    public function validate(BackupConfiguration $config): array
    {
        $errors = [];

        if (!$this->connection->isConnected()) {
            $errors[] = 'Database connection is not established';
        }

        if (!$config->getOutputPath()) {
            $errors[] = 'Output path is not specified';
        }

        return $errors;
    }

    /**
     * Generate a filename for the backup.
     */
    private function generateFilename(BackupConfiguration $config): string
    {
        $database = $this->connection->getDatabase();
        $timestamp = date('Y-m-d_H-i-s');
        $name = $config->getName() ?: 'backup';

        return \sprintf('%s_%s_%s.bak', $database, $name, $timestamp);
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
