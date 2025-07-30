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
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param BackupManager        $backupManager The backup manager service
     * @param LoggerInterface|null $logger        The logger service
     */
    public function __construct(private readonly BackupManager $backupManager, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
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
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled backup failed with exception', [
                'type' => $message->getType(),
                'name' => $message->getName(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
