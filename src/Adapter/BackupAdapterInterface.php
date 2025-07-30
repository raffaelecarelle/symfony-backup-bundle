<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter;

use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;

/**
 * Interface for backup adapters.
 */
interface BackupAdapterInterface
{
    /**
     * Perform a backup operation.
     *
     * @param BackupConfiguration $config Configuration for the backup
     *
     * @return BackupResult Result of the backup operation
     */
    public function backup(BackupConfiguration $config): BackupResult;

    /**
     * Restore from a backup.
     *
     * @param string $backupPath Path to the backup file
     * @param array  $options    Additional options for the restore operation
     *
     * @return bool True if restore was successful, false otherwise
     */
    public function restore(string $backupPath, array $options = []): bool;

    /**
     * Check if this adapter supports the given backup type.
     *
     * @param string $type Type of backup (e.g., 'mysql', 'postgresql', etc.)
     *
     * @return bool True if supported, false otherwise
     */
    public function supports(string $type): bool;

    /**
     * Validate the backup configuration.
     *
     * @param BackupConfiguration $config Configuration to validate
     *
     * @return array Array of validation errors, empty if valid
     */
    public function validate(BackupConfiguration $config): array;
}
