<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Storage;

use ProBackupBundle\Exception\BackupException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Adapter for storing backups in the local filesystem.
 */
class LocalAdapter implements StorageAdapterInterface
{
    /**
     * @var string Base directory for storing backups
     */
    private readonly string $basePath;

    private readonly Filesystem $filesystem;

    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param string $basePath    Base directory for storing backups
     * @param int    $permissions File permissions for created directories (octal)
     */
    public function __construct(
        string $basePath,
        private readonly int $permissions = 0755,
        ?LoggerInterface $logger = null,
        ?Filesystem $filesystem = null,
    ) {
        $this->basePath = rtrim($basePath, '/\\');
        $this->logger = $logger ?? new NullLogger();
        $this->filesystem = $filesystem ?? new Filesystem();

        // Ensure the base directory exists
        if (!$this->filesystem->exists($this->basePath)) {
            $this->filesystem->mkdir($this->basePath, $this->permissions);
        }
    }

    public function store(string $localPath, string $remotePath): bool
    {
        $this->logger->info('Storing file in local storage', [
            'local_path' => $localPath,
            'remote_path' => $remotePath,
        ]);

        try {
            $targetPath = $this->getFullPath($remotePath);
            $targetDir = \dirname($targetPath);

            // Ensure the target directory exists
            if (!$this->filesystem->exists($targetDir)) {
                $this->filesystem->mkdir($targetDir, $this->permissions);
            }

            // Copy the file
            $this->filesystem->copy($localPath, $targetPath, true);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store file in local storage', [
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function retrieve(string $remotePath, string $localPath): bool
    {
        $this->logger->info('Retrieving file from local storage', [
            'remote_path' => $remotePath,
            'local_path' => $localPath,
        ]);

        try {
            $sourcePath = $this->getFullPath($remotePath);

            if (!$this->filesystem->exists($sourcePath)) {
                throw new BackupException(\sprintf('File not found in local storage: %s', $remotePath));
            }

            $localDir = \dirname($localPath);

            // Ensure the local directory exists
            if (!$this->filesystem->exists($localDir)) {
                $this->filesystem->mkdir($localDir, $this->permissions);
            }

            // Copy the file
            $this->filesystem->copy($sourcePath, $localPath, true);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve file from local storage', [
                'remote_path' => $remotePath,
                'local_path' => $localPath,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $remotePath): bool
    {
        $this->logger->info('Deleting file from local storage', [
            'remote_path' => $remotePath,
        ]);

        try {
            $targetPath = $this->getFullPath($remotePath);

            if (!$this->filesystem->exists($targetPath)) {
                $this->logger->warning('File not found in local storage', [
                    'remote_path' => $remotePath,
                ]);

                return true; // Consider it a success if the file doesn't exist
            }

            $this->filesystem->remove($targetPath);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete file from local storage', [
                'remote_path' => $remotePath,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function list(string $prefix = ''): array
    {
        $this->logger->info('Listing files in local storage', [
            'prefix' => $prefix,
        ]);

        try {
            $searchPath = $this->basePath;

            if ('' !== $prefix) {
                $searchPath = $this->getFullPath($prefix);

                if (!$this->filesystem->exists($searchPath)) {
                    return [];
                }
            }

            $finder = new Finder();
            $finder->files()->in($searchPath);

            $files = [];
            foreach ($finder as $file) {
                $backupId = md5($file->getFilename());

                $files[$backupId] = [
                    'id' => $backupId,
                    'type' => basename(\dirname($file->getRealPath())),
                    'name' => $file->getFilename(),
                    'file_path' => $file->getRealPath(),
                    'file_size' => $file->getSize(),
                    'created_at' => (new \DateTimeImmutable())->setTimestamp(filemtime($file->getRealPath())),
                    'storage' => 'local',
                ];
            }

            return $files;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list files in local storage', [
                'prefix' => $prefix,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function exists(string $remotePath): bool
    {
        $targetPath = $this->getFullPath($remotePath);

        return $this->filesystem->exists($targetPath);
    }

    /**
     * Get the full path for a remote path.
     */
    private function getFullPath(string $remotePath): string
    {
        return $this->basePath.'/'.ltrim($remotePath, '/\\');
    }
}
