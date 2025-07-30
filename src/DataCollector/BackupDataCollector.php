<?php

namespace ProBackupBundle\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use ProBackupBundle\Manager\BackupManager;

/**
 * BackupDataCollector collects backup information for the Symfony Profiler.
 */
class BackupDataCollector extends DataCollector
{
    /**
     * @var BackupManager
     */
    private BackupManager $backupManager;

    /**
     * Constructor.
     *
     * @param BackupManager $backupManager
     */
    public function __construct(BackupManager $backupManager)
    {
        $this->backupManager = $backupManager;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Request $request, Response $response, \Throwable $exception = null): void
    {
        $this->data = [
            'backups' => $this->backupManager->listBackups(),
            'last_backup' => $this->backupManager->getLastBackup(),
            'storage_usage' => $this->backupManager->getStorageUsage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'backup';
    }

    /**
     * Get all available backups.
     *
     * @return array
     */
    public function getBackups(): array
    {
        return $this->data['backups'] ?? [];
    }

    /**
     * Get the last backup.
     *
     * @return array|null
     */
    public function getLastBackup(): ?array
    {
        return $this->data['last_backup'] ?? null;
    }

    /**
     * Get storage usage statistics.
     *
     * @return array
     */
    public function getStorageUsage(): array
    {
        return $this->data['storage_usage'] ?? [];
    }
}