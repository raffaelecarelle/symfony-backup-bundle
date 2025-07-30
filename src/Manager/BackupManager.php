<?php

declare(strict_types=1);

namespace ProBackupBundle\Manager;

use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\Compression\CompressionAdapterInterface;
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
    /**
     * @var BackupAdapterInterface[] List of backup adapters
     */
    private array $adapters = [];

    /**
     * @var array<string, StorageAdapterInterface> Map of storage adapters
     */
    private array $storageAdapters = [];

    /**
     * @var array<string, CompressionAdapterInterface> Map of storage adapters
     */
    private array $compressionAdapters = [];

    /**
     * @var string Default storage adapter name
     */
    private string $defaultStorage = 'local';

    private readonly LoggerInterface $logger;

    private readonly Filesystem $filesystem;

    /**
     * @var array List of available backups
     */
    private array $backups = [];

    /**
     * @var string Base directory for backups
     */
    private readonly string $backupDir;

    /**
     * Constructor.
     *
     * @param string $backupDir Base directory for backups
     */
    public function __construct(
        string $backupDir,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->backupDir = rtrim($backupDir, '/\\');
        $this->logger = $logger ?? new NullLogger();
        $this->filesystem = new Filesystem();
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
        $this->compressionAdapters[$name] = $adapter;

        return $this;
    }

    /**
     * Set the default storage adapter.
     */
    public function setDefaultStorage(string $name): self
    {
        if (!isset($this->storageAdapters[$name])) {
            throw new BackupException(\sprintf('Storage adapter "%s" not found', $name));
        }

        $this->defaultStorage = $name;

        return $this;
    }

    /**
     * Perform a backup operation.
     *
     * @throws BackupException If no adapter supports the backup type
     */
    public function backup(BackupConfiguration $config): BackupResult
    {
        $this->logger->info('Starting backup', ['type' => $config->getType()]);

        // Set default storage if not specified
        if (!$config->getStorage()) {
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
        if ($this->eventDispatcher) {
            $event = new BackupEvent($config);
            $this->eventDispatcher->dispatch($event, BackupEvents::PRE_BACKUP);
        }

        // Find an adapter that supports this backup type
        $adapter = $this->getAdapter($config->getType());

        // Validate the configuration
        $errors = $adapter->validate($config);
        if (!empty($errors)) {
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

            $compression = $this->compressionAdapters[$config->getCompression()] ?? null;

            if ($compression) {
                $targetPath = $compression->compress($result->getFilePath());
                $result->setFileSize(filesize($targetPath));
                $result->setFilePath($targetPath);
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
            if ($this->eventDispatcher) {
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
                ];
            } else {
                $this->logger->error('Backup failed', ['error' => $result->getError()]);

                // Dispatch backup failed event
                if ($this->eventDispatcher) {
                    $event = new BackupEvent($config, $result);
                    $this->eventDispatcher->dispatch($event, BackupEvents::BACKUP_FAILED);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Backup failed with exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $result = new BackupResult(
                false,
                null,
                null,
                null,
                null,
                $e->getMessage()
            );

            // Dispatch backup failed event
            if ($this->eventDispatcher) {
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
     * @return bool True if restore was successful, false otherwise
     *
     * @throws BackupException If the backup is not found
     */
    public function restore(string $backupId, array $options = []): bool
    {
        $backup = $this->getBackup($backupId);
        if (!$backup) {
            throw new BackupException(\sprintf('Backup with ID "%s" not found', $backupId));
        }

        $this->logger->info('Starting restore', ['backup_id' => $backupId]);

        // Dispatch pre-restore event
        if ($this->eventDispatcher) {
            $event = new BackupEvent(new BackupConfiguration());
            $this->eventDispatcher->dispatch($event, BackupEvents::PRE_RESTORE);
        }

        try {
            // Find an adapter that supports this backup type
            $adapter = $this->getAdapter($backup['type']);

            // Retrieve from remote storage if needed
            $backupPath = $backup['file_path'];
            if ('local' !== $backup['storage']) {
                $backupPath = $this->retrieveFromRemote($backup);
            }

            $extension = pathinfo((string) $backupPath, \PATHINFO_EXTENSION);
            $decompressionName = null;
            switch ($extension) {
                case 'gz':
                    $decompressionName = 'gzip';
                    break;
                case 'zip':
                    $decompressionName = 'zip';
                    break;
            }
            $compression = $this->compressionAdapters[$decompressionName] ?? null;

            if ($compression) {
                $backupPath = $compression->decompress($backupPath);
            }

            // Perform the restore
            $success = $adapter->restore($backupPath, $options);

            // Dispatch post-restore event
            if ($this->eventDispatcher) {
                $event = new BackupEvent(new BackupConfiguration());
                $this->eventDispatcher->dispatch($event, BackupEvents::POST_RESTORE);
            }

            if ($success) {
                $this->logger->info('Restore completed successfully', ['backup_id' => $backupId]);
            } else {
                $this->logger->error('Restore failed', ['backup_id' => $backupId]);

                // Dispatch restore failed event
                if ($this->eventDispatcher) {
                    $event = new BackupEvent(new BackupConfiguration());
                    $this->eventDispatcher->dispatch($event, BackupEvents::RESTORE_FAILED);
                }
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Restore failed with exception', [
                'backup_id' => $backupId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Dispatch restore failed event
            if ($this->eventDispatcher) {
                $event = new BackupEvent(new BackupConfiguration());
                $this->eventDispatcher->dispatch($event, BackupEvents::RESTORE_FAILED);
            }

            return false;
        } finally {
            if (isset($compression, $backupPath)) {
                $compression->compress($backupPath);
            }
        }
    }

    /**
     * List all available backups.
     *
     * @param BackupConfiguration|null $configuration Filter by backup type
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

        return array_values(array_filter($this->backups, fn ($backup) => $backup['type'] === $configuration->getType()));
    }

    /**
     * Get a specific backup by ID.
     *
     * @param string $id Backup ID
     *
     * @return array|null Backup data or null if not found
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
     * @param string|null $type Filter by backup type
     *
     * @return array|null Backup data or null if no backups
     */
    public function getLastBackup(?string $type = null): ?array
    {
        $backups = $this->listBackups($type);
        if (empty($backups)) {
            return null;
        }

        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

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
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete backup', [
                'backup_id' => $id,
                'exception' => $e->getMessage(),
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
    private function getAdapter(string $type): BackupAdapterInterface
    {
        // If type is 'database', try to determine the specific database type
        if ('database' === $type) {
            // Look for a database adapter that has a resolver
            foreach ($this->adapters as $adapter) {
                if (method_exists($adapter, 'getConnection')) {
                    $connection = $adapter->getConnection();

                    // Create a resolver and get the specific database type
                    $resolver = new \ProBackupBundle\Adapter\Database\DatabaseDriverResolver($connection, $this->logger);
                    $specificType = $resolver->resolveDriverType();

                    $this->logger->info('Resolved database type', [
                        'generic_type' => $type,
                        'specific_type' => $specificType,
                    ]);

                    // If we got a more specific type, use it instead
                    if ('database' !== $specificType) {
                        $type = $specificType;
                        break;
                    }
                }
            }
        }

        // Find an adapter that supports the (possibly more specific) type
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($type)) {
                return $adapter;
            }
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
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store backup remotely', [
                'storage' => $storageName,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retrieve a backup file from remote storage.
     *
     * @param array $backup Backup data
     *
     * @return string Local path to the retrieved file
     *
     * @throws BackupException If the backup cannot be retrieved
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
     * @param BackupResult|array       $backup Backup result or data
     * @param BackupConfiguration|null $config Backup configuration
     *
     * @return string Remote path
     */
    private function generateRemotePath($backup, ?BackupConfiguration $config = null): string
    {
        if ($backup instanceof BackupResult) {
            $fileName = basename((string) $backup->getFilePath());
            $type = $config ? $config->getType() : 'unknown';

            return \sprintf('%s/%s', $type, $fileName);
        }

        $fileName = basename((string) $backup['file_path']);
        $type = $backup['type'];

        return \sprintf('%s/%s', $type, $fileName);
    }

    /**
     * Load existing backups from the backup directory.
     */
    private function loadExistingBackups(?BackupConfiguration $configuration = null): array
    {
        if (!$configuration) {
            $backups = [];
            foreach ($this->storageAdapters as $storageAdapter) {
                $backups = array_merge($this->backups, $storageAdapter->list());
            }

            return $backups;
        }

        return $this->storageAdapters[$configuration?->getStorage()]->list();
    }
}
