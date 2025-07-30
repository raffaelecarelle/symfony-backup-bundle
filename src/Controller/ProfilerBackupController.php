<?php

namespace ProBackupBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;

/**
 * Controller for backup actions in the profiler.
 */
class ProfilerBackupController
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
     * Create a backup.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $type = $data['type'] ?? 'database';

        try {
            $config = new BackupConfiguration();
            $config->setType($type);
            $config->setName(sprintf('profiler_%s_%s', $type, date('Y-m-d_H-i-s')));

            $result = $this->backupManager->backup($config);

            return new JsonResponse([
                'success' => $result->isSuccess(),
                'backup_id' => $result->getId(),
                'file_path' => $result->getFilePath(),
                'file_size' => $result->getFileSize(),
                'error' => $result->getError()
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Restore a backup.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function restore(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $backupId = $data['backup_id'];

        try {
            $backup = $this->backupManager->getBackup($backupId);
            if (!$backup) {
                throw new \InvalidArgumentException(sprintf('Backup with ID "%s" not found', $backupId));
            }

            $success = $this->backupManager->restore($backupId);

            return new JsonResponse(['success' => $success]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Download a backup.
     *
     * @param Request $request
     * @return Response
     */
    public function download(Request $request): Response
    {
        $backupId = $request->query->get('id');

        try {
            $backup = $this->backupManager->getBackup($backupId);
            if (!$backup) {
                throw new \InvalidArgumentException(sprintf('Backup with ID "%s" not found', $backupId));
            }

            return new BinaryFileResponse(
                $backup['file_path'],
                200,
                [
                    'Content-Disposition' => sprintf('attachment; filename="%s"', basename($backup['file_path']))
                ]
            );
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), 404);
        }
    }

    /**
     * Delete a backup.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function delete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $backupId = $data['backup_id'];

        try {
            $success = $this->backupManager->deleteBackup($backupId);
            return new JsonResponse(['success' => $success]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}