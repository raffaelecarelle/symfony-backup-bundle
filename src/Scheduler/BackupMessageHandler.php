<?php

declare(strict_types=1);

namespace ProBackupBundle\Scheduler;

use ProBackupBundle\Manager\BackupManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for scheduled backup messages.
 */
#[AsMessageHandler]
class BackupMessageHandler
{
    /**
     * Constructor.
     *
     * @param BackupManager        $backupManager The backup manager service
     * @param null|LoggerInterface $logger        The logger service
     */
    public function __construct(private readonly BackupManager $backupManager, private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Handle a backup message.
     *
     * @param BackupMessage $message The backup message
     */
    public function __invoke(BackupMessage $message): void
    {
        $this->logger->info('Starting scheduled backup', [
            'type' => $message->getType(),
            'name' => $message->getName(),
        ]);

        try {
            // Convert message to configuration
            $config = $message->toConfiguration();

            // Perform backup
            $result = $this->backupManager->backup($config);

            if ($result->isSuccess()) {
                $this->logger->info('Scheduled backup completed successfully', [
                    'type' => $message->getType(),
                    'name' => $message->getName(),
                    'file' => $result->getFilePath(),
                    'size' => $result->getFileSize(),
                    'duration' => $result->getDuration(),
                ]);
            } else {
                $this->logger->error('Scheduled backup failed', [
                    'type' => $message->getType(),
                    'name' => $message->getName(),
                    'error' => $result->getError(),
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('Scheduled backup failed with exception', [
                'type' => $message->getType(),
                'name' => $message->getName(),
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);
        }
    }
}
