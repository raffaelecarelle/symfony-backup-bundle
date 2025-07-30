<?php

declare(strict_types=1);

namespace ProBackupBundle\DataCollector;

use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * BackupDataCollector collects backup information for the Symfony Profiler.
 */
class BackupDataCollector extends DataCollector
{
    /**
     * Constructor.
     */
    public function __construct(private readonly BackupManager $backupManager)
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'backups' => $this->backupManager->listBackups(),
            'last_backup' => $this->backupManager->getLastBackup(),
            'storage_usage' => $this->backupManager->getStorageUsage(),
        ];
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function getName(): string
    {
        return 'backup';
    }

    /**
     * Get all available backups.
     */
    public function getBackups(): array
    {
        return $this->data['backups'] ?? [];
    }

    /**
     * Get the last backup.
     */
    public function getLastBackup(): ?array
    {
        return $this->data['last_backup'] ?? null;
    }

    /**
     * Get storage usage statistics.
     */
    public function getStorageUsage(): array
    {
        return $this->data['storage_usage'] ?? [];
    }
}
