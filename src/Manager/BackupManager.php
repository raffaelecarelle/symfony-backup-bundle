<?php

declare(strict_types=1);

namespace ProBackupBundle\Manager;

use Doctrine\Persistence\ManagerRegistry;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\Compression\CompressionAdapterInterface;
use ProBackupBundle\Adapter\DatabaseConnectionInterface;
use ProBackupBundle\Adapter\Storage\StorageAdapterInterface;
use ProBackupBundle\Event\BackupEvent;
use ProBackupBundle\Event\BackupEvents;
use ProBackupBundle\Exception\BackupException;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Main service for managing backup operations.
 */
class BackupManager
{
    /** @var array<string,mixed> */
    private array $config = [];

    /**
     * @var BackupAdapterInterface[] List of backup adapters
     */
    private array $adapters = [];

    /**
     * @var array<string, StorageAdapterInterface> Map of storage adapters
     */
    private array $storageAdapters = [];

    /**
     * @var string Default storage adapter name
     */
    private string $defaultStorage = 'local';

    private readonly Filesystem $filesystem;

    /**
     * @var array List of available backups
     */
    private array $backups = [];

    /**
     * @var string Base directory for backups
     */
    private readonly string $backupDir;

    private readonly ArchiveManager $archiveManager;

    /**
     * Constructor.
     *
     * @param string $backupDir Base directory for backups
     */
    public function __construct(
        string $backupDir,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?LoggerInterface $logger = new NullLogger(),
        private readonly ?ManagerRegistry $doctrine = null,
    ) {
        $this->backupDir = rtrim($backupDir, '/\\');
        $this->filesystem = new Filesystem();
        $this->archiveManager = new ArchiveManager();
    }

    /**
     * Add a backup adapter.
     */
    public function addAdapter(BackupAdapterInterface $adapter): self
    {
        $this->adapters[] = $adapter;

        return $this;
    }

    /**
     * Add a storage adapter.
     */
    public function addStorageAdapter(string $name, StorageAdapterInterface $adapter): self
    {
        $this->storageAdapters[$name] = $adapter;

        return $this;
    }

    /**
     * Add a compression adapter.
     */
    public function addCompressionAdapter(string $name, CompressionAdapterInterface $adapter): self
    {
        $this->archiveManager->addCompressionAdapter($name, $adapter);

        return $this;
    }

    /**
     * Set the default storage adapter.
     */
    public function setDefaultStorage(string $name): self
    {
        // Do not throw here because DI may call this before adapters are registered.
        // We validate lazily when the storage is actually used.
        if (!isset($this->storageAdapters[$name])) {
            $this->defaultStorage = $name; // set anyway; will be validated on first use if needed

            return $this;
        }

        $this->defaultStorage = $name;

        return $this;
    }

    /**
     * Inject full bundle configuration (used for retention, scheduler context, etc.).
     *
     * @param array<string,mixed> $config
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Perform a backup operation.
     *
     * @throws BackupException If no adapter supports the backup type
     */
    public function backup(BackupConfiguration $config): BackupResult
    {
        $this->logger?->info('Starting backup', ['type' => $config->getType()]);

        // Set default storage if not specified
        if ('' === $config->getStorage() || '0' === $config->getStorage()) {
            $config->setStorage($this->defaultStorage);
        }

        // Set default output path if not specified
        if (null === $config->getOutputPath()) {
            $outputPath = \sprintf('%s/%s', $this->backupDir, $config->getType());
            $config->setOutputPath($outputPath);

            // Ensure the output directory exists
            if (!$this->filesystem->exists($outputPath)) {
                $this->filesystem->mkdir($outputPath, 0755);
            }
        }

        // Dispatch pre-backup event
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $event = new BackupEvent($config);
            $this->eventDispatcher->dispatch($event, BackupEvents::PRE_BACKUP);
        }

        // Find an adapter that supports this backup type
        $adapter = $this->getAdapter($config->getType(), $config->getConnectionName());

        // Validate the configuration
        $errors = $adapter->validate($config);
        if ([] !== $errors) {
            $errorMessage = \sprintf('Invalid backup configuration: %s', implode(', ', $errors));
            $this->logger->error($errorMessage);

            return new BackupResult(
                false,
                null,
                null,
                null,
                null,
                $errorMessage
            );
        }

        try {
            // Perform the backup
            $startTime = microtime(true);

            $result = $adapter->backup($config);

            if ($result->isSuccess() && null !== $result->getFilePath() && null !== $config->getCompression()) {
                $source = $result->getFilePath();

                // Build output filename
                $timestamp = date('Y-m-d_H-i-s');
                $name = $config->getName() ?: $config->getType();
                $extension = 'zip' === $config->getCompression() ? 'zip' : 'tar.gz';
                $filename = \sprintf('%s_%s.%s', $name, $timestamp, $extension);
                $targetPath = rtrim((string) $config->getOutputPath(), '/') . '/' . $filename;

                // Compress using ArchiveManager
                $finalPath = $this->archiveManager->compress($source, $targetPath, $config->getCompression(), true);

                // Update result
                $result->setFilePath($finalPath);
                $result->setFileSize(filesize($finalPath));
                $result->setMetadataValue('compression', $config->getCompression());
            }

            // Set the duration if not already set
            if (null === $result->getDuration()) {
                $result->setDuration(microtime(true) - $startTime);
            }

            // Store remotely if needed and successful
            if ($result->isSuccess() && 'local' !== $config->getStorage()) {
                $this->storeRemotely($result, $config);
            }

            // Dispatch post-backup event
            if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                $event = new BackupEvent($config, $result);
                $this->eventDispatcher->dispatch($event, BackupEvents::POST_BACKUP);
            }

            if ($result->isSuccess()) {
                $this->logger->info('Backup completed successfully', [
                    'file' => $result->getFilePath(),
                    'size' => $result->getFileSize(),
                    'duration' => $result->getDuration(),
                ]);

                // Add to list of backups
                $this->backups[$result->getId()] = [
                    'id' => $result->getId(),
                    'type' => $config->getType(),
                    'name' => $config->getName(),
                    'file_path' => $result->getFilePath(),
                    'file_size' => $result->getFileSize(),
                    'created_at' => $result->getCreatedAt(),
                    'storage' => $config->getStorage(),
                    'metadata' => $result->getMetadata(),
                ];

                // Apply retention policy for this backup type
                try {
                    $this->applyRetentionPolicy($config->getType());
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to apply retention policy', [
                        'type' => $config->getType(),
                        'exception' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->logger->error('Backup failed', ['error' => $result->getError()]);

                // Dispatch backup failed event
                if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                    $event = new BackupEvent($config, $result);
                    $this->eventDispatcher->dispatch($event, BackupEvents::BACKUP_FAILED);
                }
            }

            return $result;
        } catch (\Throwable $throwable) {
            $this->logger->error('Backup failed with exception', [
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            $result = new BackupResult(
                false,
                null,
                null,
                null,
                null,
                $throwable->getMessage()
            );

            // Dispatch backup failed event
            if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                $event = new BackupEvent($config, $result);
                $this->eventDispatcher->dispatch($event, BackupEvents::BACKUP_FAILED);
            }

            return $result;
        }
    }

    /**
     * Restore from a backup.
     *
     * @param string $backupId ID of the backup to restore
     * @param array  $options  Additional options for the restore operation
     *
     * @throws BackupException If the backup is not found
     *
     * @return bool True if restore was successful, false otherwise
     */
    public function restore(string $backupId, array $options = []): bool
    {
        $backup = $this->getBackup($backupId);
        if (!$backup) {
            throw new BackupException(\sprintf('Backup with ID "%s" not found', $backupId));
        }

        $this->logger->info('Starting restore', ['backup_id' => $backupId]);

        // Dispatch pre-restore event
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $event = new BackupEvent(new BackupConfiguration());
            $this->eventDispatcher->dispatch($event, BackupEvents::PRE_RESTORE);
        }

        try {
            $adapter = $this->getAdapter($backup['type'], $options['connection_name'] ?? null);

            // Retrieve from remote storage if needed
            $backupPath = $backup['file_path'];
            if ('local' !== $backup['storage']) {
                $backupPath = $this->retrieveFromRemote($backup);
            }

            $backupPath = $this->archiveManager->decompress($backupPath, null, $options['keep_original'] ?? false);

            $success = $adapter->restore($backupPath, $options);

            if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                $event = new BackupEvent(new BackupConfiguration());
                $this->eventDispatcher->dispatch($event, BackupEvents::POST_RESTORE);
            }

            if ($success) {
                $this->logger->info('Restore completed successfully', ['backup_id' => $backupId]);
            } else {
                $this->logger->error('Restore failed', ['backup_id' => $backupId]);

                if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                    $event = new BackupEvent(new BackupConfiguration());
                    $this->eventDispatcher->dispatch($event, BackupEvents::RESTORE_FAILED);
                }
            }

            return $success;
        } catch (\Throwable $throwable) {
            $this->logger->error('Restore failed with exception', [
                'backup_id' => $backupId,
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            // Dispatch restore failed event
            if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                $event = new BackupEvent(new BackupConfiguration());
                $this->eventDispatcher->dispatch($event, BackupEvents::RESTORE_FAILED);
            }

            return false;
        } finally {
            // Cleanup any temporary resources created during restore preparation
            if (isset($backupPath) && null !== $backupPath && $this->filesystem->exists((string) $backupPath)) {
                $this->filesystem->remove((string) $backupPath);
            }
        }

        return isset($success) && (bool) $success;
    }

    /**
     * List all available backups.
     *
     * @param null|BackupConfiguration $configuration Filter by backup type
     *
     * @return array List of backups
     */
    public function listBackups(?BackupConfiguration $configuration = null): array
    {
        if ([] === $this->backups) {
            $this->backups = $this->loadExistingBackups($configuration);
        }

        if (null === $configuration?->getType()) {
            return array_values($this->backups);
        }

        return array_values(array_filter($this->backups, fn (array $backup): bool => $backup['type'] === $configuration->getType()));
    }

    /**
     * Get a specific backup by ID.
     *
     * @param string $id Backup ID
     *
     * @return null|array Backup data or null if not found
     */
    public function getBackup(string $id): ?array
    {
        if ([] === $this->backups) {
            $this->backups = $this->loadExistingBackups();
        }

        return $this->backups[$id] ?? null;
    }

    /**
     * Get the most recent backup.
     *
     * @param null|string $type Filter by backup type
     *
     * @return null|array Backup data or null if no backups
     */
    public function getLastBackup(?string $type = null): ?array
    {
        $backups = $this->listBackups($type);
        if ([] === $backups) {
            return null;
        }

        usort($backups, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $backups[0];
    }

    /**
     * Delete a backup.
     *
     * @param string $id Backup ID
     *
     * @return bool True if deleted successfully, false otherwise
     */
    public function deleteBackup(string $id): bool
    {
        $backup = $this->getBackup($id);
        if (!$backup) {
            return false;
        }

        $this->logger->info('Deleting backup', ['backup_id' => $id]);

        try {
            // Delete from remote storage if needed
            if ('local' !== $backup['storage']) {
                $storageAdapter = $this->storageAdapters[$backup['storage']];
                $remotePath = $this->generateRemotePath($backup);
                $storageAdapter->delete($remotePath);
            }

            // Delete local file
            if ($this->filesystem->exists($backup['file_path'])) {
                $this->filesystem->remove($backup['file_path']);
            }

            // Remove from list of backups
            unset($this->backups[$id]);

            $this->logger->info('Backup deleted successfully', ['backup_id' => $id]);

            return true;
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to delete backup', [
                'backup_id' => $id,
                'exception' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get storage usage statistics.
     *
     * @return array Storage usage statistics
     */
    public function getStorageUsage(): array
    {
        $total = 0;
        $byType = [];

        foreach ($this->backups as $backup) {
            $type = $backup['type'];
            $size = $backup['file_size'] ?? 0;

            $total += $size;
            $byType[$type] = ($byType[$type] ?? 0) + $size;
        }

        return [
            'total' => $total,
            'by_type' => $byType,
        ];
    }

    /**
     * Get an adapter that supports the given backup type.
     *
     * @param string $type Backup type
     *
     * @throws BackupException If no adapter supports the backup type
     */
    private function getAdapter(string $type, ?string $connectionName): BackupAdapterInterface
    {
        // Pass 1: Prefer non-DB adapters that explicitly support the type (e.g., filesystem, custom)
        foreach ($this->adapters as $adapter) {
            if (!($adapter instanceof DatabaseConnectionInterface) && $adapter->supports($type)) {
                return $adapter;
            }
        }

        // Pass 2: Consider DB adapters (require Doctrine context if available)
        foreach ($this->adapters as $adapter) {
            if (!($adapter instanceof DatabaseConnectionInterface)) {
                continue;
            }

            $doctrine = $this->doctrine ?? null;
            $resolvedConnectionName = $connectionName;
            if (null === $resolvedConnectionName) {
                $resolvedConnectionName = $doctrine?->getDefaultConnectionName();
            }

            if (!$doctrine instanceof ManagerRegistry || null === $resolvedConnectionName) {
                // No Doctrine context available -> skip DB adapters
                continue;
            }

            $connection = $adapter->getConnection();
            $requestedConnection = $doctrine->getConnection($resolvedConnectionName);

            if (
                $requestedConnection !== $connection
                && $requestedConnection->getDatabasePlatform() !== $connection->getDatabasePlatform()
            ) {
                continue;
            }

            return $adapter;
        }

        throw new BackupException(\sprintf('No adapter found for backup type "%s"', $type));
    }

    /**
     * Store a backup file in remote storage.
     *
     * @param BackupResult        $result Backup result
     * @param BackupConfiguration $config Backup configuration
     *
     * @return bool True if stored successfully, false otherwise
     */
    private function storeRemotely(BackupResult $result, BackupConfiguration $config): bool
    {
        $storageName = $config->getStorage();
        if (!isset($this->storageAdapters[$storageName])) {
            $this->logger->error('Storage adapter not found', ['storage' => $storageName]);

            return false;
        }

        $storage = $this->storageAdapters[$storageName];
        $remotePath = $this->generateRemotePath($result, $config);

        $this->logger->info('Storing backup remotely', [
            'storage' => $storageName,
            'remote_path' => $remotePath,
        ]);

        try {
            return $storage->store($result->getFilePath(), $remotePath);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to store backup remotely', [
                'storage' => $storageName,
                'exception' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retrieve a backup file from remote storage.
     *
     * @param array $backup Backup data
     *
     * @throws BackupException If the backup cannot be retrieved
     *
     * @return string Local path to the retrieved file
     */
    private function retrieveFromRemote(array $backup): string
    {
        $storageName = $backup['storage'];
        if (!isset($this->storageAdapters[$storageName])) {
            throw new BackupException(\sprintf('Storage adapter "%s" not found', $storageName));
        }

        $storage = $this->storageAdapters[$storageName];
        $remotePath = $this->generateRemotePath($backup);
        $localPath = \sprintf('%s/tmp/%s', $this->backupDir, basename((string) $backup['file_path']));

        // Ensure the tmp directory exists
        $tmpDir = \dirname($localPath);
        if (!$this->filesystem->exists($tmpDir)) {
            $this->filesystem->mkdir($tmpDir, 0755);
        }

        $this->logger->info('Retrieving backup from remote storage', [
            'storage' => $storageName,
            'remote_path' => $remotePath,
            'local_path' => $localPath,
        ]);

        if (!$storage->retrieve($remotePath, $localPath)) {
            throw new BackupException('Failed to retrieve backup from remote storage');
        }

        return $localPath;
    }

    /**
     * Generate a remote path for a backup file.
     *
     * @param array|BackupResult       $backup Backup result or data
     * @param null|BackupConfiguration $config Backup configuration
     *
     * @return string Remote path
     */
    private function generateRemotePath(array|BackupResult $backup, ?BackupConfiguration $config = null): string
    {
        if ($backup instanceof BackupResult) {
            $fileName = basename((string) $backup->getFilePath());
            $type = $config instanceof BackupConfiguration ? $config->getType() : 'unknown';

            return \sprintf('%s/%s', $type, $fileName);
        }

        $fileName = basename((string) $backup['file_path']);
        $type = $backup['type'];

        return \sprintf('%s/%s', $type, $fileName);
    }

    /**
     * Apply retention policy by deleting old backups beyond configured retention days.
     *
     * @param null|string $type   'database' | 'filesystem' (null = both)
     * @param bool        $dryRun If true, only logs actions without deleting
     */
    public function applyRetentionPolicy(?string $type = null, bool $dryRun = false): void
    {
        $types = $type ? [$type] : ['database', 'filesystem'];

        foreach ($types as $t) {
            $days = (int) ($this->config[$t]['retention_days'] ?? 0);
            if ($days <= 0) {
                $this->logger?->info('Retention disabled or zero days, skipping', ['type' => $t]);
                continue;
            }

            $cutoff = (new \DateTimeImmutable(\sprintf('-%d days', $days)));
            $this->logger?->info('Applying retention policy', ['type' => $t, 'days' => $days, 'cutoff' => $cutoff->format(\DATE_ATOM)]);

            foreach ($this->storageAdapters as $storageName => $adapter) {
                // List entries under the type prefix
                $entries = $adapter->list($t);

                foreach ($entries as $entry) {
                    // LocalAdapter schema
                    if (isset($entry['created_at']) && isset($entry['name']) && isset($entry['type'])) {
                        $createdAt = $entry['created_at'] instanceof \DateTimeInterface ? $entry['created_at'] : new \DateTimeImmutable('@' . $entry['created_at']);
                        if ($createdAt < $cutoff) {
                            $remotePath = $entry['type'] . '/' . $entry['name'];
                            if ($dryRun) {
                                $this->logger?->info('Retention dry-run: would delete old backup', [
                                    'storage' => $storageName,
                                    'path' => $remotePath,
                                    'created_at' => $createdAt->format(\DATE_ATOM),
                                ]);
                                continue;
                            }

                            $ok = $adapter->delete($remotePath);
                            $this->logger?->info($ok ? 'Deleted old backup due to retention' : 'Failed to delete old backup', [
                                'storage' => $storageName,
                                'path' => $remotePath,
                                'created_at' => $createdAt->format(\DATE_ATOM),
                            ]);
                        }

                        continue;
                    }

                    // S3/Google schema
                    if (isset($entry['path']) && isset($entry['modified'])) {
                        $modified = $entry['modified'] instanceof \DateTimeInterface ? $entry['modified'] : new \DateTimeImmutable((string) $entry['modified']);
                        if ($modified < $cutoff) {
                            $remotePath = $entry['path'];
                            if ($dryRun) {
                                $this->logger?->info('Retention dry-run: would delete old remote backup', [
                                    'storage' => $storageName,
                                    'path' => $remotePath,
                                    'modified' => $modified->format(\DATE_ATOM),
                                ]);
                                continue;
                            }

                            $ok = $adapter->delete($remotePath);
                            $this->logger?->info($ok ? 'Deleted old remote backup due to retention' : 'Failed to delete old remote backup', [
                                'storage' => $storageName,
                                'path' => $remotePath,
                                'modified' => $modified->format(\DATE_ATOM),
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Load existing backups from the backup directory.
     */
    private function loadExistingBackups(?BackupConfiguration $configuration = null): array
    {
        if (!$configuration instanceof BackupConfiguration) {
            $backups = [];
            foreach ($this->storageAdapters as $storageAdapter) {
                $backups = array_merge($this->backups, $storageAdapter->list());
            }

            return $backups;
        }

        return $this->storageAdapters[$configuration?->getStorage()]->list();
    }
}
