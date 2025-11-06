<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Manager;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Storage\LocalAdapter;
use ProBackupBundle\Adapter\Storage\StorageAdapterInterface;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;
use Symfony\Component\Filesystem\Filesystem;

class RetentionPolicyTest extends TestCase
{
    private string $tmpDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->tmpDir = sys_get_temp_dir().'/pro_backup_tests_'.uniqid('', true);
        $this->fs->mkdir($this->tmpDir.'/database', 0777);
        $this->fs->mkdir($this->tmpDir.'/filesystem', 0777);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir) && $this->fs->exists($this->tmpDir)) {
            $this->fs->remove($this->tmpDir);
        }
    }

    private function createManagerWithLocal(string $backupDir, array $config): BackupManager
    {
        $manager = new BackupManager($backupDir, null, null, null);
        $local = new LocalAdapter($backupDir);
        $manager->addStorageAdapter('local', $local);
        $manager->setDefaultStorage('local');
        $manager->setConfig($config);

        return $manager;
    }

    public function testApplyRetentionLocalDeletesOldKeepsNew(): void
    {
        // Arrange: create two files under database/, one old (> 3 days) and one fresh (now)
        $oldFile = $this->tmpDir.'/database/old.sql';
        $newFile = $this->tmpDir.'/database/new.sql';
        $this->fs->dumpFile($oldFile, 'old');
        $this->fs->dumpFile($newFile, 'new');
        // set mtime: old file = now - 3 days
        touch($oldFile, time() - 3 * 24 * 3600);
        touch($newFile, time());

        $config = [
            'database' => ['retention_days' => 2],
            'filesystem' => ['retention_days' => 7],
        ];
        $manager = $this->createManagerWithLocal($this->tmpDir, $config);

        // Act
        $manager->applyRetentionPolicy('database');

        // Assert: old file removed, new file exists
        self::assertFileDoesNotExist($oldFile, 'Old backup should be deleted by retention');
        self::assertFileExists($newFile, 'Recent backup should be kept');
    }

    public function testApplyRetentionDryRunDoesNotDelete(): void
    {
        // Arrange
        $file = $this->tmpDir.'/database/very_old.sql';
        $this->fs->dumpFile($file, 'x');
        touch($file, time() - 10 * 24 * 3600);

        $config = [
            'database' => ['retention_days' => 1],
            'filesystem' => ['retention_days' => 7],
        ];
        $manager = $this->createManagerWithLocal($this->tmpDir, $config);

        // Act
        $manager->applyRetentionPolicy('database', true); // dry-run

        // Assert: file still exists
        self::assertFileExists($file, 'Dry-run must not delete files');
    }

    public function testApplyRetentionWithRemoteSchemaDeletesOld(): void
    {
        // Arrange: mock a remote adapter that returns entries with path/modified
        $adapter = $this->createMock(StorageAdapterInterface::class);
        $cutoff = (new \DateTimeImmutable('-5 days'));
        $oldModified = (new \DateTimeImmutable('-10 days'));
        $newModified = (new \DateTimeImmutable('-1 day'));

        $adapter->expects(self::once())
            ->method('list')
            ->with('database')
            ->willReturn([
                ['path' => 'database/old.dump', 'size' => 10, 'modified' => $oldModified],
                ['path' => 'database/new.dump', 'size' => 10, 'modified' => $newModified],
            ]);

        // delete should be called only for the old one
        $adapter->expects(self::once())
            ->method('delete')
            ->with('database/old.dump')
            ->willReturn(true);

        // Any other methods may be called or not; we don't care here
        $manager = new BackupManager($this->tmpDir, null, null, null);
        $manager->addStorageAdapter('remote', $adapter);
        $manager->setConfig([
            'database' => ['retention_days' => 5],
            'filesystem' => ['retention_days' => 7],
        ]);

        // Act
        $manager->applyRetentionPolicy('database');

        // Assert: expectations on mock verify behavior
        $this->addToAssertionCount(1); // if we reached here, mock expectations passed
    }

    public function testRetentionDisabledSkipsDeletions(): void
    {
        $adapter = $this->createMock(StorageAdapterInterface::class);
        $adapter->expects(self::never())->method('delete');
        $adapter->expects(self::never())->method('list');

        $manager = new BackupManager($this->tmpDir, null, null, null);
        $manager->addStorageAdapter('remote', $adapter);
        $manager->setConfig([
            'database' => ['retention_days' => 0],
            'filesystem' => ['retention_days' => 0],
        ]);

        $manager->applyRetentionPolicy('database');
        $this->addToAssertionCount(1);
    }

    public function testRetentionIsTriggeredAfterSuccessfulBackup(): void
    {
        // Seed old file under database to be removed after backup
        $oldFile = $this->tmpDir.'/database/old_to_purge.sql';
        $this->fs->mkdir(\dirname($oldFile));
        $this->fs->dumpFile($oldFile, 'x');
        touch($oldFile, time() - 3 * 24 * 3600);

        $config = [
            'database' => ['retention_days' => 1, 'compression' => null],
            'filesystem' => ['retention_days' => 7],
            'backup_dir' => $this->tmpDir,
            'default_storage' => 'local',
        ];

        $manager = $this->createManagerWithLocal($this->tmpDir, $config);
        // add a minimal adapter that supports 'database' and writes a file
        $manager->addAdapter(new class implements \ProBackupBundle\Adapter\BackupAdapterInterface {
            public function backup(BackupConfiguration $config): \ProBackupBundle\Model\BackupResult
            {
                $out = rtrim((string) $config->getOutputPath(), '/').'/dummy.sql';
                if (!is_dir(\dirname($out))) {
                    mkdir(\dirname($out), 0777, true);
                }
                file_put_contents($out, 'content');

                return new \ProBackupBundle\Model\BackupResult(true, $out, filesize($out) ?: 0, new \DateTimeImmutable(), 0.1);
            }

            public function restore(string $backupPath, array $options = []): bool
            {
                return false;
            }

            public function supports(string $type): bool
            {
                return 'database' === $type;
            }

            public function validate(BackupConfiguration $config): array
            {
                return [];
            }
        });

        // Perform backup
        $cfg = (new BackupConfiguration('database', 'test'))
            ->setOutputPath($this->tmpDir.'/database');
        $result = $manager->backup($cfg);
        self::assertTrue($result->isSuccess());

        // After backup, retention should have been applied: old file should be removed
        self::assertFileDoesNotExist($oldFile);
    }
}
