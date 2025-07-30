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
 * Adapter for MySQL database backups.
 */
class MySQLAdapter implements BackupAdapterInterface
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

        $this->logger->info('Starting MySQL backup', [
            'database' => $this->connection->getDatabase(),
            'output' => $filepath,
        ]);

        try {
            $command = $this->buildMysqlDumpCommand($config, $filepath);

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Apply compression if needed
            $compressedPath = $this->compressIfNeeded($filepath, $config);
            $finalPath = $compressedPath ?: $filepath;

            $this->logger->info('MySQL backup completed', [
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
            $this->logger->error('MySQL backup failed', [
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
        $this->logger->info('Starting MySQL restore', [
            'database' => $this->connection->getDatabase(),
            'file' => $backupPath,
        ]);

        try {
            // Decompress if needed
            $decompressedPath = $this->decompressIfNeeded($backupPath);
            $finalPath = $decompressedPath ?: $backupPath;

            $command = $this->buildMysqlRestoreCommand($finalPath, $options);

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Clean up temporary decompressed file
            if ($decompressedPath && $decompressedPath !== $backupPath) {
                $this->filesystem->remove($decompressedPath);
            }

            $this->logger->info('MySQL restore completed', [
                'database' => $this->connection->getDatabase(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('MySQL restore failed', [
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
        return 'mysql' === $type || 'database' === $type;
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

        return \sprintf('%s_%s_%s.sql', $database, $name, $timestamp);
    }

    /**
     * Build the mysqldump command.
     */
    private function buildMysqlDumpCommand(BackupConfiguration $config, string $filepath): string
    {
        $params = $this->connection->getParams();
        $options = $config->getOptions();
        $excludeTables = $config->getExclusions();

        $host = $params['host'] ?? 'localhost';
        $port = $params['port'] ?? 3306;
        $user = $params['user'] ?? 'root';
        $password = $params['password'] ?? '';
        $database = $this->connection->getDatabase();

        $command = \sprintf(
            'mysqldump --host=%s --port=%s --user=%s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user)
        );

        if ($password) {
            $command .= ' --password='.escapeshellarg($password);
        }

        if ($options['single_transaction'] ?? true) {
            $command .= ' --single-transaction';
        }

        if ($options['add_drop_table'] ?? true) {
            $command .= ' --add-drop-table';
        }

        if ($options['routines'] ?? false) {
            $command .= ' --routines';
        }

        if ($options['triggers'] ?? false) {
            $command .= ' --triggers';
        }

        if ($options['no_data'] ?? false) {
            $command .= ' --no-data';
        }

        foreach ($excludeTables as $table) {
            $command .= ' --ignore-table='.escapeshellarg($database.'.'.$table);
        }

        $command .= ' '.escapeshellarg((string) $database).' > '.escapeshellarg($filepath);

        return $command;
    }

    /**
     * Build the mysql restore command.
     */
    private function buildMysqlRestoreCommand(string $filepath, array $options): string
    {
        $params = $this->connection->getParams();

        $host = $params['host'] ?? 'localhost';
        $port = $params['port'] ?? 3306;
        $user = $params['user'] ?? 'root';
        $password = $params['password'] ?? '';
        $database = $this->connection->getDatabase();

        $command = \sprintf(
            'mysql --host=%s --port=%s --user=%s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user)
        );

        if ($password) {
            $command .= ' --password='.escapeshellarg($password);
        }

        if ($options['force'] ?? false) {
            $command .= ' --force';
        }

        $command .= ' '.escapeshellarg((string) $database).' < '.escapeshellarg($filepath);

        return $command;
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
