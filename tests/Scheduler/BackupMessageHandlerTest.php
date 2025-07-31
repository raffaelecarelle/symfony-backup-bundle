<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Scheduler;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use ProBackupBundle\Scheduler\BackupMessage;
use ProBackupBundle\Scheduler\BackupMessageHandler;
use Psr\Log\LoggerInterface;

class BackupMessageHandlerTest extends TestCase
{
    private $mockBackupManager;
    private $mockLogger;
    private $handler;
    private $message;
    private $configuration;

    protected function setUp(): void
    {
        $this->mockBackupManager = $this->createMock(BackupManager::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->handler = new BackupMessageHandler($this->mockBackupManager, $this->mockLogger);

        $this->message = new BackupMessage('database');
        $this->message->setName('test_backup');

        $this->configuration = new BackupConfiguration();
        $this->configuration->setType('database');
        $this->configuration->setName('test_backup');
    }

    public function testInvokeWithSuccessfulBackup(): void
    {
        // Create a successful backup result
        $result = new BackupResult(
            true,
            '/path/to/backup.sql.gz',
            1024,
            new \DateTimeImmutable(),
            1.5
        );

        // Configure mocks
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->with($this->callback(fn (BackupConfiguration $config) => 'database' === $config->getType() && 'test_backup' === $config->getName()))
            ->willReturn($result);

        // Expect logger calls
        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                ['Starting scheduled backup', $this->anything()],
                ['Scheduled backup completed successfully', $this->callback(fn ($context) => 'database' === $context['type']
                   && 'test_backup' === $context['name']
                   && '/path/to/backup.sql.gz' === $context['file']
                   && 1024 === $context['size']
                   && 1.5 === $context['duration'])]
            );

        // Invoke the handler
        $this->handler->__invoke($this->message);
    }

    public function testInvokeWithFailedBackup(): void
    {
        // Create a failed backup result
        $result = new BackupResult(
            false,
            null,
            null,
            new \DateTimeImmutable(),
            0.5,
            'Database connection failed'
        );

        // Configure mocks
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturn($result);

        // Expect logger calls
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('Starting scheduled backup', $this->anything());

        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Scheduled backup failed', $this->callback(fn ($context) => 'database' === $context['type']
                   && 'test_backup' === $context['name']
                   && 'Database connection failed' === $context['error']));

        // Invoke the handler
        $this->handler->__invoke($this->message);
    }

    public function testInvokeWithException(): void
    {
        // Configure mocks
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willThrowException(new \Exception('Unexpected error occurred'));

        // Expect logger calls
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('Starting scheduled backup', $this->anything());

        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Scheduled backup failed with exception', $this->callback(fn ($context) => 'database' === $context['type']
                   && 'test_backup' === $context['name']
                   && 'Unexpected error occurred' === $context['exception']));

        // Invoke the handler
        $this->handler->__invoke($this->message);
    }

    public function testConstructorWithDefaultLogger(): void
    {
        // Create handler without logger
        $handler = new BackupMessageHandler($this->mockBackupManager);

        // Create a successful backup result
        $result = new BackupResult(true, '/path/to/backup.sql.gz', 1024);

        // Configure mocks
        $this->mockBackupManager->expects($this->once())
            ->method('backup')
            ->willReturn($result);

        // Invoke the handler (should not throw exception)
        $handler->__invoke($this->message);

        // No assertions needed - we're just checking that it doesn't throw an exception
        $this->assertTrue(true);
    }
}
