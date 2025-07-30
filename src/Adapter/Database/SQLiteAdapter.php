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

            $this->logger->info('SQLite backup completed', [
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
            $this->filesystem->copy($backupPath, $dbPath, true);

            $this->logger->info('SQLite restore completed', [
                'database' => $dbPath,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('SQLite restore failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
}
