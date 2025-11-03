<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupSchedule;
use ProBackupBundle\Scheduler\BackupScheduler;

/**
 * End-to-end test for scheduled backup functionality.
 */
class ScheduledBackupTest extends AbstractEndToEndTest
{
    private string $dbPath;
    private BackupScheduler $scheduler;

    protected function setupTest(): void
    {
        // Create a test SQLite database
        $this->dbPath = $this->createTempSQLiteDatabase('scheduled_test.db', [
            'users' => [
                'id' => 'INTEGER PRIMARY KEY',
                'name' => 'TEXT',
            ],
        ]);

        // Insert some test data
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");

        // Create a scheduler
        $this->scheduler = new BackupScheduler($this->backupManager);
    }

    public function testScheduleBackup(): void
    {
        // Create a backup configuration
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setCompression('gzip');
        $config->setName('scheduled_backup_test');

        // Create a schedule for immediate execution
        $schedule = new BackupSchedule();
        $schedule->setConfiguration($config);
        $schedule->setFrequency('daily');
        $schedule->setNextRun(new \DateTimeImmutable('-1 minute')); // Set to run immediately
        $schedule->setEnabled(true);

        // Add the schedule to the scheduler
        $this->scheduler->addSchedule($schedule);

        // Run the scheduler
        $results = $this->scheduler->runDue();

        // Verify results
        $this->assertCount(1, $results, 'Should have one result');
        $this->assertTrue($results[0]->isSuccess(), 'Backup should be successful');

        // Verify the backup file exists
        $this->assertTrue($this->filesystem->exists($results[0]->getFilePath()), 'Backup file should exist');

        // Verify the next run time was updated
        $this->assertGreaterThan(new \DateTimeImmutable(), $schedule->getNextRun(), 'Next run should be in the future');
    }

    public function testScheduleDisabled(): void
    {
        // Create a backup configuration
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setName('disabled_schedule_test');

        // Create a schedule that is disabled
        $schedule = new BackupSchedule();
        $schedule->setConfiguration($config);
        $schedule->setFrequency('daily');
        $schedule->setNextRun(new \DateTimeImmutable('-1 minute')); // Set to run immediately
        $schedule->setEnabled(false); // Disabled

        // Add the schedule to the scheduler
        $this->scheduler->addSchedule($schedule);

        // Run the scheduler
        $results = $this->scheduler->runDue();

        // Verify no backups were created
        $this->assertCount(0, $results, 'Should have no results for disabled schedule');
    }

    public function testScheduleNotDue(): void
    {
        // Create a backup configuration
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setName('future_schedule_test');

        // Create a schedule for future execution
        $schedule = new BackupSchedule();
        $schedule->setConfiguration($config);
        $schedule->setFrequency('daily');
        $schedule->setNextRun(new \DateTimeImmutable('+1 day')); // Set to run in the future
        $schedule->setEnabled(true);

        // Add the schedule to the scheduler
        $this->scheduler->addSchedule($schedule);

        // Run the scheduler
        $results = $this->scheduler->runDue();

        // Verify no backups were created
        $this->assertCount(0, $results, 'Should have no results for future schedule');
    }

    public function testMultipleSchedules(): void
    {
        // Create two backup configurations
        $config1 = new BackupConfiguration('database');
        $config1->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config1->setName('multi_schedule_test_1');

        $config2 = new BackupConfiguration('database');
        $config2->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config2->setName('multi_schedule_test_2');

        // Create schedules
        $schedule1 = new BackupSchedule();
        $schedule1->setConfiguration($config1);
        $schedule1->setFrequency('daily');
        $schedule1->setNextRun(new \DateTimeImmutable('-1 minute')); // Due now
        $schedule1->setEnabled(true);

        $schedule2 = new BackupSchedule();
        $schedule2->setConfiguration($config2);
        $schedule2->setFrequency('daily');
        $schedule2->setNextRun(new \DateTimeImmutable('-1 minute')); // Due now
        $schedule2->setEnabled(true);

        $schedule3 = new BackupSchedule();
        $schedule3->setConfiguration($config1);
        $schedule3->setFrequency('daily');
        $schedule3->setNextRun(new \DateTimeImmutable('+1 day')); // Not due
        $schedule3->setEnabled(true);

        // Add the schedules to the scheduler
        $this->scheduler->addSchedule($schedule1);
        $this->scheduler->addSchedule($schedule2);
        $this->scheduler->addSchedule($schedule3);

        // Run the scheduler
        $results = $this->scheduler->runDue();

        // Verify results
        $this->assertCount(2, $results, 'Should have two results');
        $this->assertTrue($results[0]->isSuccess(), 'First backup should be successful');
        $this->assertTrue($results[1]->isSuccess(), 'Second backup should be successful');
    }
}
