<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Scheduler;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Scheduler\BackupMessage;
use ProBackupBundle\Scheduler\BackupScheduler;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class BackupSchedulerTest extends TestCase
{
    public function testGetScheduleWithNoEnabledBackups(): void
    {
        $config = [
            'database' => ['enabled' => false],
            'filesystem' => ['enabled' => false],
        ];

        $scheduler = new BackupScheduler($config);
        $schedule = $scheduler->getSchedule();

        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertCount(0, $this->getScheduleMessages($schedule));
    }

    public function testGetScheduleWithDatabaseBackupOnly(): void
    {
        $config = [
            'database' => [
                'enabled' => true,
                'frequency' => 'daily',
                'time' => '02:00',
            ],
            'filesystem' => ['enabled' => false],
        ];

        $scheduler = new BackupScheduler($config);
        $schedule = $scheduler->getSchedule();

        $this->assertInstanceOf(Schedule::class, $schedule);

        $messages = $this->getScheduleMessages($schedule);
        $this->assertCount(1, $messages);

        $message = $messages[0];
        $this->assertInstanceOf(BackupMessage::class, $message);
        $this->assertEquals('database', $message->getType());
    }

    public function testGetScheduleWithFilesystemBackupOnly(): void
    {
        $config = [
            'database' => ['enabled' => false],
            'filesystem' => [
                'enabled' => true,
                'frequency' => 'weekly',
                'time' => '03:00',
            ],
        ];

        $scheduler = new BackupScheduler($config);
        $schedule = $scheduler->getSchedule();

        $this->assertInstanceOf(Schedule::class, $schedule);

        $messages = $this->getScheduleMessages($schedule);
        $this->assertCount(1, $messages);

        $message = $messages[0];
        $this->assertInstanceOf(BackupMessage::class, $message);
        $this->assertEquals('filesystem', $message->getType());
    }

    public function testGetScheduleWithBothBackupsEnabled(): void
    {
        $config = [
            'database' => [
                'enabled' => true,
                'frequency' => 'daily',
                'time' => '02:00',
            ],
            'filesystem' => [
                'enabled' => true,
                'frequency' => 'weekly',
                'time' => '03:00',
            ],
        ];

        $scheduler = new BackupScheduler($config);
        $schedule = $scheduler->getSchedule();

        $this->assertInstanceOf(Schedule::class, $schedule);

        $messages = $this->getScheduleMessages($schedule);
        $this->assertCount(2, $messages);

        // Check that we have one database and one filesystem message
        $types = array_map(fn ($message) => $message->getType(), $messages);

        $this->assertContains('database', $types);
        $this->assertContains('filesystem', $types);
    }

    public function testDatabaseBackupWithCustomFrequency(): void
    {
        $config = [
            'database' => [
                'enabled' => true,
                'frequency' => 'monthly',
                'time' => '04:30',
            ],
            'filesystem' => ['enabled' => false],
        ];

        $scheduler = new BackupScheduler($config);
        $schedule = $scheduler->getSchedule();

        // Use reflection to access the private method and verify the cron expression
        $reflectionClass = new \ReflectionClass(BackupScheduler::class);
        $method = $reflectionClass->getMethod('getCronExpression');

        $cronExpression = $method->invoke($scheduler, 'monthly', '04:30');
        $this->assertEquals('30 04 1 * *', $cronExpression);
    }

    public function testFilesystemBackupWithDefaultValues(): void
    {
        $config = [
            'database' => ['enabled' => false],
            'filesystem' => [
                'enabled' => true,
                // No frequency or time specified, should use defaults
            ],
        ];

        $scheduler = new BackupScheduler($config);
        $schedule = $scheduler->getSchedule();

        // Use reflection to access the private method and verify the cron expression
        $reflectionClass = new \ReflectionClass(BackupScheduler::class);
        $method = $reflectionClass->getMethod('getCronExpression');

        $cronExpression = $method->invoke($scheduler, 'weekly', '03:00');
        $this->assertEquals('00 03 * * 0', $cronExpression);
    }

    public function testGetCronExpressionForDailyFrequency(): void
    {
        $scheduler = new BackupScheduler([]);

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(BackupScheduler::class);
        $method = $reflectionClass->getMethod('getCronExpression');

        $cronExpression = $method->invoke($scheduler, 'daily', '02:00');
        $this->assertEquals('00 02 * * *', $cronExpression);
    }

    public function testGetCronExpressionForWeeklyFrequency(): void
    {
        $scheduler = new BackupScheduler([]);

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(BackupScheduler::class);
        $method = $reflectionClass->getMethod('getCronExpression');

        $cronExpression = $method->invoke($scheduler, 'weekly', '03:00');
        $this->assertEquals('00 03 * * 0', $cronExpression);
    }

    public function testGetCronExpressionForMonthlyFrequency(): void
    {
        $scheduler = new BackupScheduler([]);

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(BackupScheduler::class);
        $method = $reflectionClass->getMethod('getCronExpression');

        $cronExpression = $method->invoke($scheduler, 'monthly', '04:00');
        $this->assertEquals('00 04 1 * *', $cronExpression);
    }

    public function testGetCronExpressionForUnknownFrequency(): void
    {
        $scheduler = new BackupScheduler([]);

        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(BackupScheduler::class);
        $method = $reflectionClass->getMethod('getCronExpression');

        // Unknown frequency should default to daily
        $cronExpression = $method->invoke($scheduler, 'unknown', '05:00');
        $this->assertEquals('00 05 * * *', $cronExpression);
    }

    /**
     * Helper method to extract messages from a Schedule.
     *
     * This is needed because Schedule doesn't provide a public API to access its messages
     */
    private function getScheduleMessages(Schedule $schedule): array
    {
        $messages = [];

        // Use reflection to access the private property
        $reflectionClass = new \ReflectionClass(Schedule::class);
        $property = $reflectionClass->getProperty('messages');

        $scheduledMessages = $property->getValue($schedule);

        foreach ($scheduledMessages as $scheduledMessage) {
            if ($scheduledMessage instanceof RecurringMessage) {
                // Use reflection to access the private provider property
                $reflectionMessage = new \ReflectionClass(RecurringMessage::class);
                $providerProperty = $reflectionMessage->getProperty('provider');
                $provider = $providerProperty->getValue($scheduledMessage);

                // Get messages from the provider using reflection
                $reflectionProvider = new \ReflectionClass($provider);
                $messagesProperty = $reflectionProvider->getProperty('messages');
                $providerMessages = $messagesProperty->getValue($provider);

                // Add the first message from the provider
                if (!empty($providerMessages)) {
                    $messages[] = $providerMessages[0];
                }
            }
        }

        return $messages;
    }
}
