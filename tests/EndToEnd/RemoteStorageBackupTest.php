<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

class RemoteStorageBackupTest extends AbstractEndToEndTest
{
    private string $dbPath;

    protected function setupTest(): void
    {
        // Create a small SQLite DB to back up
        $this->dbPath = $this->createTempSQLiteDatabase('remote_test.db', [
            'items' => [
                'id' => 'INTEGER PRIMARY KEY',
                'name' => 'TEXT',
            ],
        ]);

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO items (id, name) VALUES (1, 'One'), (2, 'Two')");
    }

    public function testDatabaseBackupStoredRemotelyAndRestored(): void
    {
        // Configure a database backup using the additional remote_local storage
        $config = new BackupConfiguration('database');
        $config->setOption('connection', [
            'driver' => 'sqlite',
            'path' => $this->dbPath,
        ]);
        $config->setCompression('gzip');
        $config->setName('remote_sqlite_backup');
        $config->setStorage('remote_local');

        $result = $this->backupManager->backup($config);

        $this->assertTrue($result->isSuccess(), 'Backup should be successful');
        $this->assertNotNull($result->getFilePath());

        // Verify that the backup has been copied to the remote storage location
        $projectDir = (string) $this->container->getParameter('kernel.project_dir');
        $remoteBase = $projectDir.'/var/remote_storage';
        $remotePath = $remoteBase.'/database/'.basename((string) $result->getFilePath());
        $this->assertTrue($this->filesystem->exists($remotePath), 'Remote storage copy should exist');

        // Now restore from the backup id; BackupManager will pull from remote storage
        $restoreDbPath = $this->tempDir.'/restored_remote.db';
        $restored = $this->backupManager->restore($result->getId(), [
            'connection' => [
                'driver' => 'sqlite',
                'path' => $restoreDbPath,
            ],
        ]);

        $this->assertTrue($restored, 'Restore should be successful');
        $this->assertTrue($this->filesystem->exists($restoreDbPath), 'Restored DB should exist');

        // Check restored data integrity
        $pdo = new \PDO('sqlite:'.$restoreDbPath);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        $this->assertSame(2, $count, 'Restored table should have 2 rows');
        $name = (string) $pdo->query('SELECT name FROM items WHERE id = 2')->fetchColumn();
        $this->assertSame('Two', $name, 'Restored data should match');
    }
}
