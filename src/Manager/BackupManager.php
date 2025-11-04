<?php

declare(strict_types=1);

namespace ProBackupBundle\Manager;

use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\Compression\CompressionAdapterInterface;
use ProBackupBundle\Adapter\Database\DatabaseDriverResolver;
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
        private readonly ?LoggerInterface $logger = new NullLogger(),
        /**
         * @var array List of available doctrine connections
         */
        private readonly array $doctrineConnections = [],
    ) {
        $this->backupDir = rtrim($backupDir, '/\\');
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
        $adapter = $this->getAdapter($config->getType(), $config->getConnectionName());

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

            // Apply compression centrally per type (database and filesystem)
            if ($result->isSuccess()) {
                if ('database' === $config->getType() && null !== $result->getFilePath()) {
                    $compression = $this->compressionAdapters[$config->getCompression()] ?? null;
                    if ($compression) {
                        $targetPath = $compression->compress($result->getFilePath(), null, ['keep_original' => false]);
                        $result->setFileSize(filesize($targetPath));
                        $result->setFilePath($targetPath);
                        $result->setMetadataValue('compression', $config->getCompression());
                    }
                } elseif ('filesystem' === $config->getType() && null !== $result->getFilePath()) {
                    // The filesystem adapter returns a staging directory. Create the final archive here.
                    $stagingDir = $result->getFilePath();

                    // Build output filename consistent with previous behavior
                    $timestamp = date('Y-m-d_H-i-s');
                    $name = $config->getName() ?: 'filesystem';
                    $extension = 'zip' === $config->getCompression() ? 'zip' : 'tar.gz';
                    $filename = sprintf('%s_%s.%s', $name, $timestamp, $extension);
                    $targetPath = rtrim((string) $config->getOutputPath(), '/').'/'.$filename;

                    // Ensure output directory exists
                    if (!$this->filesystem->exists(dirname($targetPath))) {
                        $this->filesystem->mkdir(dirname($targetPath), 0755);
                    }

                    $compressionName = $config->getCompression() ?? 'zip';
                    if ('zip' === strtolower($compressionName)) {
                        $zip = $this->compressionAdapters['zip'] ?? null;
                        if (!$zip) {
                            throw new BackupException('Zip compression adapter not available');
                        }
                        $zip->compress($stagingDir, $targetPath, ['keep_original' => true]);
                    } else {
                        // gzip requires a tar first, then gzip it
                        $gzip = $this->compressionAdapters['gzip'] ?? null;
                        if (!$gzip) {
                            throw new BackupException('Gzip compression adapter not available');
                        }
                        $tarPath = preg_replace('/\.gz$/', '', $targetPath) ?: ($targetPath.'.tar');

                        // Create tar from staging directory contents (without top-level folder)
                        $tarCmd = sprintf('tar -cf %s -C %s .', escapeshellarg($tarPath), escapeshellarg($stagingDir));
                        $proc = \Symfony\Component\Process\Process::fromShellCommandline($tarCmd);
                        $proc->setTimeout(3600);
                        $proc->run();
                        if (!$proc->isSuccessful()) {
                            throw new \Symfony\Component\Process\Exception\ProcessFailedException($proc);
                        }

                        try {
                            $gzip->compress($tarPath, $targetPath, ['keep_original' => false]);
                        } finally {
                            if ($this->filesystem->exists($tarPath)) {
                                $this->filesystem->remove($tarPath);
                            }
                        }
                    }

                    // Update result and cleanup staging directory
                    $result->setFilePath($targetPath);
                    $result->setFileSize(filesize($targetPath));
                    $result->setMetadataValue('compression', $config->getCompression());

                    // Remove staging directory
                    if ($this->filesystem->exists($stagingDir)) {
                        $this->filesystem->remove($stagingDir);
                    }
                }
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
                    'metadata' => $result->getMetadata(),
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
            $adapter = $this->getAdapter($backup['type'], $options['connection_name'] ?? null);

            // Retrieve from remote storage if needed
            $backupPath = $backup['file_path'];
            if ('local' !== $backup['storage']) {
                $backupPath = $this->retrieveFromRemote($backup);
            }

            $recompressor = null;
            $recompressSource = null;

            if ('database' === $backup['type']) {
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
                    // Decompress while keeping the original archive, then schedule recompress to restore state
                    $backupPath = $compression->decompress($backupPath, null, ['keep_original' => true]);
                    $recompressor = $compression;
                    $recompressSource = $backupPath; // the decompressed file to re-compress later
                }
            } elseif ('filesystem' === $backup['type']) {
                // For filesystem, extract the archive to a temporary directory
                $extension = pathinfo((string) $backupPath, \PATHINFO_EXTENSION);
                $tempExtractDir = sys_get_temp_dir().'/restore_'.uniqid('', true);
                $this->filesystem->mkdir($tempExtractDir, 0755);

                if ('zip' === strtolower((string) $extension)) {
                    $zip = $this->compressionAdapters['zip'] ?? null;
                    if (!$zip) {
                        throw new BackupException('Zip compression adapter not available for restore');
                    }
                    // Extract into the temp directory, keep original archive intact
                    $zip->decompress($backupPath, $tempExtractDir, ['keep_original' => true]);
                    $backupPath = $tempExtractDir;
                } elseif ('gz' === strtolower((string) $extension)) {
                    // Expect .tar.gz: first gunzip to a tar, then extract tar
                    $gzip = $this->compressionAdapters['gzip'] ?? null;
                    if (!$gzip) {
                        throw new BackupException('Gzip compression adapter not available for restore');
                    }
                    $tarPath = preg_replace('/\.gz$/', '', (string) $backupPath) ?: ($backupPath.'.tar');
                    try {
                        $gzip->decompress((string) $backupPath, $tarPath, ['keep_original' => true]);
                        // Extract tar into the temp directory
                        $tarCmd = sprintf('tar -xf %s -C %s', escapeshellarg((string) $tarPath), escapeshellarg($tempExtractDir));
                        $proc = \Symfony\Component\Process\Process::fromShellCommandline($tarCmd);
                        $proc->setTimeout(3600);
                        $proc->run();
                        if (!$proc->isSuccessful()) {
                            throw new \Symfony\Component\Process\Exception\ProcessFailedException($proc);
                        }
                    } finally {
                        if (isset($tarPath) && $this->filesystem->exists((string) $tarPath)) {
                            $this->filesystem->remove((string) $tarPath);
                        }
                    }
                    $backupPath = $tempExtractDir;
                } else {
                    // Unknown extension: pass-through (could be a plain directory already)
                    if (!is_dir((string) $backupPath)) {
                        throw new BackupException('Unsupported filesystem backup format: '.$extension);
                    }
                }

                // Ensure cleanup of extracted directory after restore
                $cleanupExtractDir = $backupPath;
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
            // Cleanup any temporary resources created during restore preparation
            if (isset($cleanupExtractDir) && is_dir((string) $cleanupExtractDir) && $this->filesystem->exists((string) $cleanupExtractDir)) {
                $this->filesystem->remove((string) $cleanupExtractDir);
            }
            if (isset($recompressSource) && null !== $recompressSource && $this->filesystem->exists((string) $recompressSource)) {
                // We kept the original archive untouched; remove the temporary decompressed file
                $this->filesystem->remove((string) $recompressSource);
            }
        }

        return isset($success) ? (bool) $success : false;
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
    private function getAdapter(string $type, ?string $connectionName): BackupAdapterInterface
    {
        // If type is 'database', try to determine the specific database type
        if ('database' === $type) {
            // Look for a database adapter that has a resolver
            foreach ($this->adapters as $adapter) {
                if (method_exists($adapter, 'getConnection')) {
                    $connection = $adapter->getConnection();

                    if ($connectionName) {
                        $connection = $this->doctrineConnections[$connectionName] ?? throw new BackupException(\sprintf('Doctrine connection "%s" not found', $connectionName));
                    }

                    // Create a resolver and get the specific database type
                    $resolver = new DatabaseDriverResolver($connection, $this->logger);
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
