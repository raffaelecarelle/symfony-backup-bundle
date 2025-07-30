<?php

declare(strict_types=1);

namespace ProBackupBundle\Scheduler;

use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Scheduler for automated backups.
 */
class BackupScheduler implements ScheduleProviderInterface
{
    /**
     * Constructor.
     *
     * @param BackupManager $backupManager  The backup manager service
     * @param array         $scheduleConfig The schedule configuration
     */
    public function __construct(private readonly BackupManager $backupManager, private array $scheduleConfig)
    {
    }

    /**
     * Get the schedule of tasks.
     *
     * @return Schedule The schedule
     */
    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

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
     * Configure database backup schedule.
     *
     * @param Schedule $schedule The schedule to configure
     */
    private function configureDatabaseBackup(Schedule $schedule): void
    {
        $frequency = $this->scheduleConfig['database']['frequency'] ?? 'daily';
        $time = $this->scheduleConfig['database']['time'] ?? '02:00';

        $cronExpression = $this->getCronExpression($frequency, $time);

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
        $frequency = $this->scheduleConfig['filesystem']['frequency'] ?? 'weekly';
        $time = $this->scheduleConfig['filesystem']['time'] ?? '03:00';

        $cronExpression = $this->getCronExpression($frequency, $time);

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
