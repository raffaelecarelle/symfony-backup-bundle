<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\DatabaseConnectionInterface;
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
class MySQLAdapter implements BackupAdapterInterface, DatabaseConnectionInterface
{
    private readonly Filesystem $filesystem;

    /**
     * Constructor.
     */
    public function __construct(private readonly Connection $connection, private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Get the database connection.
     *
     * @return Connection The database connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function backup(BackupConfiguration $config): BackupResult
    {
        $startTime = microtime(true);
        $filename = $this->generateFilename($config);
        $outputPath = $config->getOutputPath();
        if (null === $outputPath) {
            throw new \ProBackupBundle\Exception\BackupException('Output path is not specified');
        }
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

            $size = filesize($filepath);
            $size = false === $size ? 0 : $size;

            $this->logger->info('MySQL backup completed', [
                'file' => $filepath,
                'size' => $size,
                'duration' => microtime(true) - $startTime,
            ]);

            return new BackupResult(
                true,
                $filepath,
                $size,
                new \DateTimeImmutable(),
                microtime(true) - $startTime,
                null,
                ['compression' => $config->getCompression() ?? '(none)']
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
            $command = $this->buildMysqlRestoreCommand($backupPath, $options);

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
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
        $port = $params['port'] ?? '3306';
        $user = $params['user'] ?? 'root';
        $password = $params['password'] ?? '';
        $database = $this->connection->getDatabase();

        $command = \sprintf(
            'mysqldump --host=%s --port=%s --user=%s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
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
     *
     * @param array<string, mixed> $options
     */
    private function buildMysqlRestoreCommand(string $filepath, array $options): string
    {
        $params = $this->connection->getParams();

        $host = $params['host'] ?? 'localhost';
        $port = $params['port'] ?? '3306';
        $user = $params['user'] ?? 'root';
        $password = $params['password'] ?? '';
        $database = $this->connection->getDatabase();

        $command = \sprintf(
            'mysql --host=%s --port=%s --user=%s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
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
}
