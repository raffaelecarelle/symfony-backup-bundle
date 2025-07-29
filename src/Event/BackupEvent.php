<?php

namespace Symfony\Component\Backup\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Component\Backup\Model\BackupConfiguration;
use Symfony\Component\Backup\Model\BackupResult;

/**
 * Event dispatched during backup and restore operations.
 */
class BackupEvent extends Event
{
    private BackupConfiguration $configuration;
    private ?BackupResult $result;
    
    /**
     * @param BackupConfiguration $configuration The backup configuration
     * @param BackupResult|null $result The backup result (null for pre-backup events)
     */
    public function __construct(BackupConfiguration $configuration, ?BackupResult $result = null)
    {
        $this->configuration = $configuration;
        $this->result = $result;
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