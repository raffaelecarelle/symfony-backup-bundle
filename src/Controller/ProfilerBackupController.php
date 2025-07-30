<?php

declare(strict_types=1);

namespace ProBackupBundle\Controller;

use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Controller for backup actions in the profiler.
 */
class ProfilerBackupController
{
    /**
     * Constructor.
     */
    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly DataCollector $backupDataCollector,
    ) {
    }

    /**
     * Backups list.
     */
    public function list(): JsonResponse
    {
        try {
            $backups = $this->backupManager->listBackups();

            return new JsonResponse([
                'success' => true,
                'backups' => $backups,
                'count' => \count($backups),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a backup.
     */
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $type = $data['type'] ?? 'database';

        try {
            $config = new BackupConfiguration();
            $config->setType($type);
            $config->setName(\sprintf('profiler_%s_%s', $type, date('Y-m-d_H-i-s')));

            $result = $this->backupManager->backup($config);

            $this->backupDataCollector->reset();

            return new JsonResponse([
                'success' => $result->isSuccess(),
                'backup_id' => $result->getId(),
                'file_path' => $result->getFilePath(),
                'file_size' => $result->getFileSize(),
                'error' => $result->getError(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restore a backup.
     */
    public function restore(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $backupId = $data['backup_id'];

        try {
            $backup = $this->backupManager->getBackup($backupId);
            if (!$backup) {
                throw new \InvalidArgumentException(\sprintf('Backup with ID "%s" not found', $backupId));
            }

            $success = $this->backupManager->restore($backupId);

            return new JsonResponse(['success' => $success]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download a backup.
     */
    public function download(Request $request): Response
    {
        $backupId = $request->query->get('id');

        try {
            $backup = $this->backupManager->getBackup($backupId);
            if (!$backup) {
                throw new \InvalidArgumentException(\sprintf('Backup with ID "%s" not found', $backupId));
            }

            return new BinaryFileResponse(
                $backup['file_path'],
                200,
                [
                    'Content-Disposition' => \sprintf('attachment; filename="%s"', basename((string) $backup['file_path'])),
                ]
            );
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), 404);
        }
    }

    /**
     * Delete a backup.
     */
    public function delete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $backupId = $data['backup_id'];

        try {
            $success = $this->backupManager->deleteBackup($backupId);

            $this->backupDataCollector->reset();

            return new JsonResponse(['success' => $success]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
