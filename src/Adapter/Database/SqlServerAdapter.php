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

/**
 * Adapter for SQL Server database backups.
 */
class SqlServerAdapter implements BackupAdapterInterface
{
    private readonly Filesystem $filesystem;

    /**
     * Constructor.
     */
    public function __construct(private readonly Connection $connection, private readonly ?LoggerInterface $logger = new NullLogger())
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

            $this->logger->info('SQL Server backup completed', [
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
            $database = $this->connection->getDatabase();
            $backupFile = str_replace('\\', '\\\\', $backupPath); // Escape backslashes for T-SQL

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
}
