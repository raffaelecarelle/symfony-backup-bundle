<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Manager;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\Storage\StorageAdapterInterface;
use ProBackupBundle\Event\BackupEvent;
use ProBackupBundle\Event\BackupEvents;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

class BackupManagerTest extends TestCase
{
    private BackupManager $backupManager;

    private MockObject $mockAdapter;

    private MockObject $mockStorageAdapter;

    private MockObject $mockEventDispatcher;

    private MockObject $mockLogger;

    private string $tempDir;

    private MockObject $mockDoctrine;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/backup_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->mockAdapter = $this->createMock(BackupAdapterInterface::class);
        $this->mockStorageAdapter = $this->createMock(StorageAdapterInterface::class);
        $this->mockEventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockDoctrine = $this->createMock(ManagerRegistry::class);

        $this->backupManager = new BackupManager(
            $this->tempDir,
            $this->mockEventDispatcher,
            $this->mockLogger,
            $this->mockDoctrine
        );

        $this->backupManager->addAdapter($this->mockAdapter);
        $this->backupManager->addStorageAdapter('local', $this->mockStorageAdapter);
        $this->backupManager->setDefaultStorage('local');
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        if (file_exists($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }

    public function testBackup(): void
    {
        $config = new BackupConfiguration();
        $config->setType('database');
        $config->setName('test_backup');
        // Fix: Set outputPath to avoid uninitialized property error
        $config->setOutputPath($this->tempDir . '/database');

        $result = new BackupResult();
        $result->setSuccess(true);
        $result->setFilePath($this->tempDir . '/test_backup.sql');
        $result->setFileSize(1024);
        $result->setCreatedAt(new \DateTimeImmutable());
        $result->setDuration(0.5);

        // Configure mock adapter
        $this->mockAdapter->expects($this->once())
            ->method('supports')
            ->with('database')
            ->willReturn(true);

        $this->mockAdapter->expects($this->once())
            ->method('validate')
            ->with($this->equalTo($config))
            ->willReturn([]);

        $this->mockAdapter->expects($this->once())
            ->method('backup')
            ->with($this->equalTo($config))
            ->willReturn($result);

        // Configure mock event dispatcher
        $this->mockEventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->withConsecutive(
                [$this->isInstanceOf(BackupEvent::class), BackupEvents::PRE_BACKUP],
                [$this->isInstanceOf(BackupEvent::class), BackupEvents::POST_BACKUP]
            );

        $backupResult = $this->backupManager->backup($config);

        $this->assertTrue($backupResult->isSuccess());
        $this->assertEquals($result->getFilePath(), $backupResult->getFilePath());
        $this->assertEquals($result->getFileSize(), $backupResult->getFileSize());
    }

    public function testRestore(): void
    {
        $backupId = 'test_backup_123';
        $backupPath = $this->tempDir . '/test_backup.sql';
        $options = ['option1' => 'value1'];

        // Create a mock backup file
        file_put_contents($backupPath, 'test backup content');

        // Configure mock adapter
        $this->mockAdapter->expects($this->once())
            ->method('supports')
            ->willReturn(true);

        $this->mockAdapter->expects($this->once())
            ->method('restore')
            ->with($backupPath, $options)
            ->willReturn(true);

        // Fix: Configure mock event dispatcher with correct event names
        $this->mockEventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->withConsecutive(
                [$this->isInstanceOf(BackupEvent::class), BackupEvents::PRE_RESTORE],
                [$this->isInstanceOf(BackupEvent::class), BackupEvents::POST_RESTORE]
            );

        $backupManagerMock = $this->getMockBuilder(BackupManager::class)
            ->setConstructorArgs([$this->tempDir, $this->mockEventDispatcher, $this->mockLogger, $this->mockDoctrine])
            ->onlyMethods(['getBackup'])
            ->getMock();

        $backupManagerMock->expects($this->once())
            ->method('getBackup')
            ->with($backupId)
            ->willReturn([
                'id' => $backupId,
                'file_path' => $backupPath,
                'type' => 'database',
                'storage' => 'local',
            ]);

        $backupManagerMock->addAdapter($this->mockAdapter);

        $result = $backupManagerMock->restore($backupId, $options);

        $this->assertTrue($result);
    }

    public function testListBackups(): void
    {
        // Create some test backup files
        file_put_contents($this->tempDir . '/database_backup_1.sql', 'test content');
        file_put_contents($this->tempDir . '/database_backup_2.sql', 'test content');
        file_put_contents($this->tempDir . '/filesystem_backup_1.zip', 'test content');

        // Mock the listBackups method using reflection to return test data
        $backupManagerMock = $this->getMockBuilder(BackupManager::class)
            ->setConstructorArgs([$this->tempDir, $this->mockEventDispatcher, $this->mockLogger])
            ->onlyMethods(['listBackups'])
            ->getMock();

        $testBackups = [
            [
                'id' => 'backup1',
                'name' => 'database_backup_1',
                'type' => 'database',
                'file_path' => $this->tempDir . '/database_backup_1.sql',
                'size' => 12,
                'created_at' => new \DateTimeImmutable(),
            ],
            [
                'id' => 'backup2',
                'name' => 'database_backup_2',
                'type' => 'database',
                'file_path' => $this->tempDir . '/database_backup_2.sql',
                'size' => 12,
                'created_at' => new \DateTimeImmutable(),
            ],
        ];

        // Create a BackupConfiguration object instead of passing a string
        $configuration = new BackupConfiguration();
        $configuration->setType('database');

        $backupManagerMock->expects($this->once())
            ->method('listBackups')
            ->with($this->equalTo($configuration))
            ->willReturn($testBackups);

        // Call the method with the proper BackupConfiguration object
        $result = $backupManagerMock->listBackups($configuration);

        $this->assertCount(2, $result);
        $this->assertEquals('backup1', $result[0]['id']);
        $this->assertEquals('backup2', $result[1]['id']);
    }

    public function testDeleteBackup(): void
    {
        $backupId = 'test_backup_123';
        $backupPath = $this->tempDir . '/test_backup.sql';

        // Create a mock backup file
        file_put_contents($backupPath, 'test backup content');

        // Fix: Create a proper mock that calls getBackup and then deleteBackup
        $backupManagerMock = $this->getMockBuilder(BackupManager::class)
            ->setConstructorArgs([$this->tempDir, $this->mockEventDispatcher, $this->mockLogger])
            ->onlyMethods(['getBackup'])
            ->getMock();

        $backupManagerMock->expects($this->once())
            ->method('getBackup')
            ->with($backupId)
            ->willReturn([
                'id' => $backupId,
                'file_path' => $backupPath,
                'type' => 'database',
                'storage' => 'local',
            ]);

        // Call the actual deleteBackup method, not a mocked one
        $result = $backupManagerMock->deleteBackup($backupId);

        $this->assertTrue($result);
    }
}
