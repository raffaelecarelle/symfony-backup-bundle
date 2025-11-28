<?php

declare(strict_types=1);

namespace ProBackupBundle\Scheduler;

use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupResult;
use ProBackupBundle\Model\BackupSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Scheduler for automated backups.
 */
class BackupScheduler implements ScheduleProviderInterface
{
    private ?array $scheduleConfig = null;

    private ?BackupManager $manager = null;

    /** @var BackupSchedule[] */
    private array $schedules = [];

    /**
     * Constructor.
     *
     * Supports two modes:
     *  - Symfony Scheduler provider with array config
     *  - In-memory scheduler with a BackupManager for E2E tests
     */
    public function __construct(array|BackupManager $arg)
    {
        if ($arg instanceof BackupManager) {
            $this->manager = $arg;
        } else {
            $this->scheduleConfig = $arg;
        }
    }

    /**
     * Get the schedule of tasks.
     *
     * @return Schedule The schedule
     */
    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        if (null === $this->scheduleConfig) {
            return $schedule; // No config provided in in-memory mode
        }

        // Configure database backups if enabled
        if (isset($this->scheduleConfig['database']['enabled']) && $this->scheduleConfig['database']['enabled']) {
            $this->configureDatabaseBackup($schedule);
        }

        // Configure filesystem backups if enabled
        if (isset($this->scheduleConfig['filesystem']['enabled']) && $this->scheduleConfig['filesystem']['enabled']) {
            $this->configureFilesystemBackup($schedule);
        }

        return $schedule;
    }

    /**
     * In-memory: Add a schedule to the scheduler.
     */
    public function addSchedule(BackupSchedule $schedule): self
    {
        $this->schedules[] = $schedule;

        return $this;
    }

    /**
     * In-memory: Run all due schedules and return their results.
     *
     * @return BackupResult[]
     */
    public function runDue(): array
    {
        if (!$this->manager instanceof BackupManager) {
            return [];
        }

        $now = new \DateTimeImmutable();
        $results = [];

        foreach ($this->schedules as $schedule) {
            if (!$schedule->isEnabled()) {
                continue;
            }

            $nextRun = $schedule->getNextRun();
            if (null === $nextRun || $nextRun > $now) {
                continue; // Not due yet
            }

            $config = $schedule->getConfiguration();
            if (null === $config) {
                continue;
            }

            $results[] = $this->manager->backup($config);

            // Update next run based on frequency
            $frequency = $schedule->getFrequency();
            $updatedNextRun = match ($frequency) {
                'daily' => $now->modify('+1 day'),
                'weekly' => $now->modify('+1 week'),
                'monthly' => $now->modify('+1 month'),
                default => $now->modify('+1 day'),
            };
            $schedule->setNextRun($updatedNextRun);
        }

        return $results;
    }

    /**
     * Configure database backup schedule.
     *
     * @param Schedule $schedule The schedule to configure
     */
    private function configureDatabaseBackup(Schedule $schedule): void
    {
        $customCron = $this->scheduleConfig['database']['cron_expression'] ?? null;
        if (\is_string($customCron) && '' !== trim($customCron)) {
            $cronExpression = $customCron;
        } else {
            $frequency = $this->scheduleConfig['database']['frequency'] ?? 'daily';
            $time = $this->scheduleConfig['database']['time'] ?? '02:00';
            $cronExpression = $this->getCronExpression($frequency, $time);
        }

        $message = new BackupMessage('database');
        $schedule->add(RecurringMessage::cron($cronExpression, $message));
    }

    /**
     * Configure filesystem backup schedule.
     *
     * @param Schedule $schedule The schedule to configure
     */
    private function configureFilesystemBackup(Schedule $schedule): void
    {
        $customCron = $this->scheduleConfig['filesystem']['cron_expression'] ?? null;
        if (\is_string($customCron) && '' !== trim($customCron)) {
            $cronExpression = $customCron;
        } else {
            $frequency = $this->scheduleConfig['filesystem']['frequency'] ?? 'weekly';
            $time = $this->scheduleConfig['filesystem']['time'] ?? '03:00';
            $cronExpression = $this->getCronExpression($frequency, $time);
        }

        $message = new BackupMessage('filesystem');
        $schedule->add(RecurringMessage::cron($cronExpression, $message));
    }

    /**
     * Convert frequency and time to cron expression.
     *
     * @param string $frequency The frequency (daily, weekly, monthly)
     * @param string $time      The time in HH:MM format
     *
     * @return string The cron expression
     */
    private function getCronExpression(string $frequency, string $time): string
    {
        // Parse time
        [$hour, $minute] = explode(':', $time);

        // Build cron expression based on frequency
        return match ($frequency) {
            'daily' => "{$minute} {$hour} * * *",
            'weekly' => "{$minute} {$hour} * * 0", // Sunday
            'monthly' => "{$minute} {$hour} 1 * *", // 1st day of month
            default => "{$minute} {$hour} * * *", // Default to daily
        };
    }
}
