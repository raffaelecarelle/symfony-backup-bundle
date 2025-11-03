<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * End-to-end test for filesystem backup functionality.
 */
class FilesystemBackupTest extends AbstractEndToEndTest
{
    private string $testFilesDir;

    protected function setupTest(): void
    {
        // Create a directory with test files
        $this->testFilesDir = $this->tempDir.'/test_files';
        $this->filesystem->mkdir($this->testFilesDir);

        // Create some test files with content
        $this->createTempFile('test_files/file1.txt', 'This is the content of file 1');
        $this->createTempFile('test_files/file2.txt', 'This is the content of file 2');

        // Create a subdirectory with files
        $this->filesystem->mkdir($this->testFilesDir.'/subdir');
        $this->createTempFile('test_files/subdir/file3.txt', 'This is the content of file 3');
        $this->createTempFile('test_files/subdir/file4.txt', 'This is the content of file 4');

        // Create a directory that should be excluded
        $this->filesystem->mkdir($this->testFilesDir.'/excluded');
        $this->createTempFile('test_files/excluded/excluded_file.txt', 'This file should be excluded');
    }

    public function testFilesystemBackup(): void
    {
        // Create backup configuration
        $config = new BackupConfiguration('filesystem');
        $config->setOption('paths', [
            [
                'path' => $this->testFilesDir,
                'exclude' => ['excluded'],
            ],
        ]);
        $config->setCompression('zip');
        $config->setName('filesystem_backup_test');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');
        $this->assertNotNull($result->getFilePath(), 'Backup file path should not be null');
        $this->assertNotNull($result->getSize(), 'Backup size should not be null');
        $this->assertGreaterThan(0, $result->getSize(), 'Backup size should be greater than 0');

        // Verify the backup file exists
        $this->assertTrue($this->filesystem->exists($result->getFilePath()), 'Backup file should exist');

        // Test restore functionality
        $restoreDir = $this->tempDir.'/restored_files';
        $this->filesystem->mkdir($restoreDir);

        $restoreResult = $this->backupManager->restore($result->getId(), [
            'target_dir' => $restoreDir,
        ]);

        $this->assertTrue($restoreResult, 'Restore should be successful');

        // Verify restored files
        $this->assertTrue($this->filesystem->exists($restoreDir.'/file1.txt'), 'file1.txt should exist');
        $this->assertTrue($this->filesystem->exists($restoreDir.'/file2.txt'), 'file2.txt should exist');
        $this->assertTrue($this->filesystem->exists($restoreDir.'/subdir/file3.txt'), 'subdir/file3.txt should exist');
        $this->assertTrue($this->filesystem->exists($restoreDir.'/subdir/file4.txt'), 'subdir/file4.txt should exist');

        // Verify excluded directory is not in the backup
        $this->assertFalse($this->filesystem->exists($restoreDir.'/excluded'), 'excluded directory should not exist');

        // Verify file contents
        $this->assertEquals(
            'This is the content of file 1',
            file_get_contents($restoreDir.'/file1.txt'),
            'file1.txt content should match'
        );

        $this->assertEquals(
            'This is the content of file 3',
            file_get_contents($restoreDir.'/subdir/file3.txt'),
            'subdir/file3.txt content should match'
        );
    }

    public function testFilesystemBackupWithCustomOptions(): void
    {
        // Create backup configuration with custom options
        $config = new BackupConfiguration('filesystem');
        $config->setOption('paths', [
            [
                'path' => $this->testFilesDir,
                'exclude' => ['excluded', 'subdir'], // Exclude subdir as well
            ],
        ]);
        $config->setCompression('gzip'); // Use gzip instead of zip
        $config->setName('filesystem_custom_test');
        $config->setStorage('local');

        // Execute backup
        $result = $this->backupManager->backup($config);

        // Assert backup was successful
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // Test restore functionality
        $restoreDir = $this->tempDir.'/restored_custom';
        $this->filesystem->mkdir($restoreDir);

        $restoreResult = $this->backupManager->restore($result->getId(), [
            'target_dir' => $restoreDir,
        ]);

        $this->assertTrue($restoreResult, 'Restore should be successful');

        // Verify restored files
        $this->assertTrue($this->filesystem->exists($restoreDir.'/file1.txt'), 'file1.txt should exist');
        $this->assertTrue($this->filesystem->exists($restoreDir.'/file2.txt'), 'file2.txt should exist');

        // Verify excluded directories are not in the backup
        $this->assertFalse($this->filesystem->exists($restoreDir.'/excluded'), 'excluded directory should not exist');
        $this->assertFalse($this->filesystem->exists($restoreDir.'/subdir'), 'subdir directory should not exist');
    }

    public function testListFilesystemBackups(): void
    {
        // Create a backup first
        $config = new BackupConfiguration('filesystem');
        $config->setOption('paths', [
            [
                'path' => $this->testFilesDir,
            ],
        ]);
        $config->setCompression('zip');
        $config->setName('filesystem_list_test');

        $result = $this->backupManager->backup($config);
        $this->assertTrue($result->isSuccess(), 'Backup should be successful');

        // List backups
        $backups = $this->backupManager->listBackups($config);

        // Assert we have at least one backup
        $this->assertNotEmpty($backups, 'Should have at least one backup');

        // Find our backup in the list
        $found = false;
        foreach ($backups as $backup) {
            if ($backup['file_path'] === $result->getFilePath()) {
                $found = true;
                $this->assertEquals('filesystem_list_test', $backup['name']);
                $this->assertEquals('filesystem', $backup['type']);
                $this->assertEquals('zip', $backup['metadata']['compression']);
                $this->assertEquals('local', $backup['storage']);
                break;
            }
        }

        $this->assertTrue($found, 'Should find our backup in the list');
    }
}
