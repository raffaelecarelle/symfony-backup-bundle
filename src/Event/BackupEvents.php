<?php

declare(strict_types=1);

namespace ProBackupBundle\Event;

/**
 * Contains all events dispatched by the Backup component.
 */
final class BackupEvents
{
    /**
     * Dispatched before a backup operation.
     */
    public const PRE_BACKUP = 'backup.pre_backup';

    /**
     * Dispatched after a successful backup operation.
     */
    public const POST_BACKUP = 'backup.post_backup';

    /**
     * Dispatched when a backup operation fails.
     */
    public const BACKUP_FAILED = 'backup.failed';

    /**
     * Dispatched before a restore operation.
     */
    public const PRE_RESTORE = 'backup.pre_restore';

    /**
     * Dispatched after a successful restore operation.
     */
    public const POST_RESTORE = 'backup.post_restore';

    /**
     * Dispatched when a restore operation fails.
     */
    public const RESTORE_FAILED = 'backup.restore_failed';
}
