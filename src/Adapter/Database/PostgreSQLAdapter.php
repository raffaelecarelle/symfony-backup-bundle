<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use ProBackupBundle\Process\Factory\ProcessFactory;
use ProBackupBundle\Process\ProcessTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adapter for PostgreSQL database backups.
 */
class PostgreSQLAdapter implements BackupAdapterInterface
{
    use ProcessTrait;

    private readonly Filesystem $filesystem;

    /**
     * Constructor.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = new NullLogger(),
        ?ProcessFactory $processFactory = null,
    ) {
        $this->filesystem = new Filesystem();
        $this->processFactory = $processFactory;
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
        $filepath = $outputPath.'/'.$filename;

        // Ensure the output directory exists
        if (!$this->filesystem->exists($outputPath)) {
            $this->filesystem->mkdir($outputPath, 0755);
        }

        $this->logger->info('Starting PostgreSQL backup', [
            'database' => $this->connection->getDatabase(),
            'output' => $filepath,
        ]);

        try {
            $command = $this->buildPgDumpCommand($config, $filepath);

            $this->executeCommand($command);

            $this->logger->info('PostgreSQL backup completed', [
                'file' => $filepath,
                'size' => filesize($filepath),
                'duration' => microtime(true) - $startTime,
            ]);

            return new BackupResult(
                true,
                $filepath,
                filesize($filepath),
                new \DateTimeImmutable(),
                microtime(true) - $startTime
            );
        } catch (\Throwable $e) {
            $this->logger->error('PostgreSQL backup failed', [
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
        $this->logger->info('Starting PostgreSQL restore', [
            'database' => $this->connection->getDatabase(),
            'file' => $backupPath,
        ]);

        try {
            $command = $this->buildPgRestoreCommand($backupPath, $options);

            $this->executeCommand($command);

            $this->logger->info('PostgreSQL restore completed', [
                'database' => $this->connection->getDatabase(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('PostgreSQL restore failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function supports(string $type): bool
    {
        return 'postgresql' === $type || 'postgres' === $type || 'pgsql' === $type || 'database' === $type;
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
        $format = $config->getOption('format', 'plain');

        $extension = 'custom' === $format ? 'dump' : 'sql';

        return \sprintf('%s_%s_%s.%s', $database, $name, $timestamp, $extension);
    }

    /**
     * Build the pg_dump command.
     */
    private function buildPgDumpCommand(BackupConfiguration $config, string $filepath): string
    {
        $params = $this->connection->getParams();
        $options = $config->getOptions();
        $excludeTables = $config->getExclusions();

        $host = $params['host'] ?? 'localhost';
        $port = $params['port'] ?? 5432;
        $user = $params['user'] ?? 'postgres';
        $password = $params['password'] ?? '';
        $database = $this->connection->getDatabase();

        // Set environment variables for password
        $env = '';
        if ($password) {
            $env = 'PGPASSWORD='.escapeshellarg($password).' ';
        }

        $command = $env.\sprintf(
            'pg_dump --host=%s --port=%s --username=%s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($user)
        );

        // Format option
        $format = $options['format'] ?? 'plain';
        if ('custom' === $format) {
            $command .= ' --format=custom';
        } else {
            $command .= ' --format=plain';
        }

        // Other options
        if ($options['schema_only'] ?? false) {
            $command .= ' --schema-only';
        }

        if ($options['data_only'] ?? false) {
            $command .= ' --data-only';
        }

        if ($options['clean'] ?? false) {
            $command .= ' --clean';
        }

        if ($options['create'] ?? true) {
            $command .= ' --create';
        }

        if ($options['verbose'] ?? false) {
            $command .= ' --verbose';
        }

        // Exclude tables
        foreach ($excludeTables as $table) {
            $command .= ' --exclude-table='.escapeshellarg((string) $table);
        }

        $command .= ' '.escapeshellarg((string) $database).' > '.escapeshellarg($filepath);

        return $command;
    }

    /**
     * Build the pg_restore command.
     */
    private function buildPgRestoreCommand(string $filepath, array $options): string
    {
        $params = $this->connection->getParams();

        $host = $params['host'] ?? 'localhost';
        $port = $params['port'] ?? 5432;
        $user = $params['user'] ?? 'postgres';
        $password = $params['password'] ?? '';
        $database = $this->connection->getDatabase();

        // Set environment variables for password
        $env = '';
        if ($password) {
            $env = 'PGPASSWORD='.escapeshellarg($password).' ';
        }

        // Check if this is a custom format backup
        $isCustomFormat = 'dump' === pathinfo($filepath, \PATHINFO_EXTENSION);

        if ($isCustomFormat) {
            // Use pg_restore for custom format
            $command = $env.\sprintf(
                'pg_restore --host=%s --port=%s --username=%s --dbname=%s',
                escapeshellarg($host),
                escapeshellarg((string) $port),
                escapeshellarg($user),
                escapeshellarg((string) $database)
            );

            if ($options['clean'] ?? false) {
                $command .= ' --clean';
            }

            if ($options['create'] ?? false) {
                $command .= ' --create';
            }

            if ($options['single_transaction'] ?? true) {
                $command .= ' --single-transaction';
            }

            if ($options['no_owner'] ?? false) {
                $command .= ' --no-owner';
            }

            $command .= ' '.escapeshellarg($filepath);
        } else {
            // Use psql for plain format
            $command = $env.\sprintf(
                'psql --host=%s --port=%s --username=%s --dbname=%s',
                escapeshellarg($host),
                escapeshellarg((string) $port),
                escapeshellarg($user),
                escapeshellarg((string) $database)
            );

            if ($options['single_transaction'] ?? true) {
                $command .= ' --single-transaction';
            }

            $command .= ' < '.escapeshellarg($filepath);
        }

        return $command;
    }
}
