<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\DatabaseConnectionInterface;
use ProBackupBundle\Exception\BackupException;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adapter for SQLite database backups.
 */
class SQLiteAdapter implements BackupAdapterInterface, DatabaseConnectionInterface
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
        $filepath = $outputPath.'/'.$filename;

        // Ensure the output directory exists
        if (!$this->filesystem->exists($outputPath)) {
            $this->filesystem->mkdir($outputPath, 0755);
        }

        // Allow overriding the database path via configuration options
        $overridePath = $config->getOption('connection')['path'] ?? null;
        $dbPathForLog = $overridePath ?: $this->getDatabasePath();

        $this->logger->info('Starting SQLite backup', [
            'database' => $dbPathForLog,
            'output' => $filepath,
        ]);

        try {
            // For SQLite, we can simply copy the database file
            $dbPath = $overridePath ?: $this->getDatabasePath();

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
        // Determine target database path: allow override via options (used by tests)
        $overridePath = $options['connection']['path'] ?? null;
        $targetDbPath = $overridePath ?: $this->getDatabasePath();

        $this->logger->info('Starting SQLite restore', [
            'database' => $targetDbPath,
            'file' => $backupPath,
        ]);

        try {
            // Ensure target directory exists
            $targetDir = \dirname((string) $targetDbPath);
            if (!$this->filesystem->exists($targetDir)) {
                $this->filesystem->mkdir($targetDir, 0755);
            }

            // Check if the database file exists and is writable
            if ($this->filesystem->exists($targetDbPath)) {
                if (!is_writable($targetDbPath)) {
                    throw new BackupException(\sprintf('SQLite database file is not writable: %s', $targetDbPath));
                }

                // Create a backup of the current database if requested (default true)
                if ($options['backup_existing'] ?? true) {
                    $backupExistingPath = $targetDbPath.'.bak.'.date('YmdHis');
                    $this->filesystem->copy($targetDbPath, $backupExistingPath);
                    $this->logger->info('Created backup of existing database', [
                        'backup_path' => $backupExistingPath,
                    ]);
                }
            }

            // Copy the backup file to the database location
            $this->filesystem->copy($backupPath, $targetDbPath, true);

            $this->logger->info('SQLite restore completed', [
                'database' => $targetDbPath,
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

        // Check for direct path parameter
        if (isset($params['path'])) {
            return $params['path'];
        }

        // Check for database name parameter (common in Symfony)
        if (isset($params['dbname'])) {
            return $params['dbname'];
        }

        // Check for URL format
        if (isset($params['url'])) {
            $url = parse_url($params['url']);
            if (isset($url['path'])) {
                // Remove leading slash if present
                return ltrim($url['path'], '/');
            } elseif (preg_match('#^sqlite:///(.*?)$#', $params['url'], $matches)) {
                // Handle sqlite:/// format
                return $matches[1];
            }
        }

        // Check for memory database
        if (isset($params['memory']) && true === $params['memory']) {
            return ':memory:';
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
