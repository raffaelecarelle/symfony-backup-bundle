<?php

declare(strict_types=1);

namespace ProBackupBundle\Event;

use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched during backup and restore operations.
 */
class BackupEvent extends Event
{
    /**
     * @param BackupConfiguration $configuration The backup configuration
     * @param BackupResult|null   $result        The backup result (null for pre-backup events)
     */
    public function __construct(private readonly BackupConfiguration $configuration, private readonly ?BackupResult $result = null)
    {
    }

    /**
     * Get the backup configuration.
     */
    public function getConfiguration(): BackupConfiguration
    {
        return $this->configuration;
    }

    /**
     * Get the backup result.
     *
     * @return BackupResult|null The backup result or null if not available
     */
    public function getResult(): ?BackupResult
    {
        return $this->result;
    }
}
