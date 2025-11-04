<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Storage;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use ProBackupBundle\Exception\BackupException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adapter for storing backups in Google Cloud Storage.
 */
class GoogleCloudAdapter implements StorageAdapterInterface
{
    private readonly StorageClient $storageClient;

    private readonly Bucket $bucket;

    /**
     * @var string Base prefix for storing backups
     */
    private string $prefix;

    private readonly Filesystem $filesystem;

    /**
     * Constructor.
     *
     * @param StorageClient $storageClient Google Cloud Storage client
     * @param string        $bucketName    Google Cloud Storage bucket name
     * @param string        $prefix        Base prefix for storing backups
     */
    public function __construct(StorageClient $storageClient, string $bucketName, string $prefix = '', private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->storageClient = $storageClient;
        $this->bucket = $this->storageClient->bucket($bucketName);
        $this->prefix = trim($prefix, '/');
        $this->filesystem = new Filesystem();

        // Add trailing slash to prefix if not empty
        if ('' !== $this->prefix) {
            $this->prefix .= '/';
        }
    }

    public function store(string $localPath, string $remotePath): bool
    {
        $this->logger->info('Storing file in Google Cloud Storage', [
            'local_path' => $localPath,
            'remote_path' => $remotePath,
            'bucket' => $this->bucket->name(),
        ]);

        try {
            if (!$this->filesystem->exists($localPath)) {
                throw new BackupException(\sprintf('Local file not found: %s', $localPath));
            }

            $objectName = $this->getFullKey($remotePath);

            $file = fopen($localPath, 'r');
            $this->bucket->upload($file, [
                'name' => $objectName,
            ]);

            if (\is_resource($file)) {
                fclose($file);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store file in Google Cloud Storage', [
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'bucket' => $this->bucket->name(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function retrieve(string $remotePath, string $localPath): bool
    {
        $this->logger->info('Retrieving file from Google Cloud Storage', [
            'remote_path' => $remotePath,
            'local_path' => $localPath,
            'bucket' => $this->bucket->name(),
        ]);

        try {
            $objectName = $this->getFullKey($remotePath);
            $object = $this->bucket->object($objectName);

            if (!$object->exists()) {
                throw new BackupException(\sprintf('File not found in Google Cloud Storage: %s', $remotePath));
            }

            $localDir = \dirname($localPath);

            // Ensure the local directory exists
            if (!$this->filesystem->exists($localDir)) {
                $this->filesystem->mkdir($localDir, 0755);
            }

            // Download the file
            $object->downloadToFile($localPath);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve file from Google Cloud Storage', [
                'remote_path' => $remotePath,
                'local_path' => $localPath,
                'bucket' => $this->bucket->name(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $remotePath): bool
    {
        $this->logger->info('Deleting file from Google Cloud Storage', [
            'remote_path' => $remotePath,
            'bucket' => $this->bucket->name(),
        ]);

        try {
            $objectName = $this->getFullKey($remotePath);
            $object = $this->bucket->object($objectName);

            if (!$object->exists()) {
                $this->logger->warning('File not found in Google Cloud Storage', [
                    'remote_path' => $remotePath,
                    'bucket' => $this->bucket->name(),
                ]);

                return true; // Consider it a success if the file doesn't exist
            }

            $object->delete();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete file from Google Cloud Storage', [
                'remote_path' => $remotePath,
                'bucket' => $this->bucket->name(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function list(string $prefix = ''): array
    {
        $this->logger->info('Listing files in Google Cloud Storage', [
            'prefix' => $prefix,
            'bucket' => $this->bucket->name(),
        ]);

        try {
            $fullPrefix = $this->getFullKey($prefix);

            $options = [
                'prefix' => $fullPrefix,
            ];

            $objects = $this->bucket->objects($options);

            $files = [];
            foreach ($objects as $object) {
                $relativePath = $this->getRelativeKey($object->name());
                $info = $object->info();

                $files[] = [
                    'path' => $relativePath,
                    'size' => $info['size'],
                    'modified' => new \DateTimeImmutable($info['updated']),
                ];
            }

            return $files;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list files in Google Cloud Storage', [
                'prefix' => $prefix,
                'bucket' => $this->bucket->name(),
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function exists(string $remotePath): bool
    {
        try {
            $objectName = $this->getFullKey($remotePath);
            $object = $this->bucket->object($objectName);

            return $object->exists();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check if file exists in Google Cloud Storage', [
                'remote_path' => $remotePath,
                'bucket' => $this->bucket->name(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the full object key for a remote path.
     */
    private function getFullKey(string $remotePath): string
    {
        return $this->prefix.ltrim($remotePath, '/');
    }

    /**
     * Get the relative path for a full object key.
     */
    private function getRelativeKey(string $fullKey): string
    {
        if ('' === $this->prefix) {
            return $fullKey;
        }

        if (str_starts_with($fullKey, $this->prefix)) {
            return substr($fullKey, \strlen($this->prefix));
        }

        return $fullKey;
    }
}
