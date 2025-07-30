<?php

namespace ProBackupBundle\Adapter\Storage;

use ProBackupBundle\Exception\BackupException;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Adapter for storing backups in Amazon S3.
 */
class S3Adapter implements StorageAdapterInterface
{
    /**
     * @var \Aws\S3\S3Client
     */
    private $s3Client;
    
    /**
     * @var string S3 bucket name
     */
    private string $bucket;
    
    /**
     * @var string Base prefix for storing backups
     */
    private string $prefix;
    
    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;
    
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param \Aws\S3\S3Client $s3Client AWS S3 client
     * @param string $bucket S3 bucket name
     * @param string $prefix Base prefix for storing backups
     * @param LoggerInterface|null $logger
     */
    public function __construct(\Aws\S3\S3Client $s3Client, string $bucket, string $prefix = '', ?LoggerInterface $logger = null)
    {
        $this->s3Client = $s3Client;
        $this->bucket = $bucket;
        $this->prefix = trim($prefix, '/');
        $this->logger = $logger ?? new NullLogger();
        $this->filesystem = new Filesystem();
        
        // Add trailing slash to prefix if not empty
        if ($this->prefix !== '') {
            $this->prefix .= '/';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function store(string $localPath, string $remotePath): bool
    {
        $this->logger->info('Storing file in S3', [
            'local_path' => $localPath,
            'remote_path' => $remotePath,
            'bucket' => $this->bucket,
        ]);
        
        try {
            if (!$this->filesystem->exists($localPath)) {
                throw new BackupException(sprintf('Local file not found: %s', $localPath));
            }
            
            $key = $this->getFullKey($remotePath);
            
            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $localPath,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store file in S3', [
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'bucket' => $this->bucket,
                'exception' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $remotePath, string $localPath): bool
    {
        $this->logger->info('Retrieving file from S3', [
            'remote_path' => $remotePath,
            'local_path' => $localPath,
            'bucket' => $this->bucket,
        ]);
        
        try {
            $key = $this->getFullKey($remotePath);
            
            // Check if the object exists
            if (!$this->s3Client->doesObjectExist($this->bucket, $key)) {
                throw new BackupException(sprintf('File not found in S3: %s', $remotePath));
            }
            
            $localDir = dirname($localPath);
            
            // Ensure the local directory exists
            if (!$this->filesystem->exists($localDir)) {
                $this->filesystem->mkdir($localDir, 0755);
            }
            
            // Download the file
            $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SaveAs' => $localPath,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve file from S3', [
                'remote_path' => $remotePath,
                'local_path' => $localPath,
                'bucket' => $this->bucket,
                'exception' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $remotePath): bool
    {
        $this->logger->info('Deleting file from S3', [
            'remote_path' => $remotePath,
            'bucket' => $this->bucket,
        ]);
        
        try {
            $key = $this->getFullKey($remotePath);
            
            // Check if the object exists
            if (!$this->s3Client->doesObjectExist($this->bucket, $key)) {
                $this->logger->warning('File not found in S3', [
                    'remote_path' => $remotePath,
                    'bucket' => $this->bucket,
                ]);
                
                return true; // Consider it a success if the file doesn't exist
            }
            
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete file from S3', [
                'remote_path' => $remotePath,
                'bucket' => $this->bucket,
                'exception' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function list(string $prefix = ''): array
    {
        $this->logger->info('Listing files in S3', [
            'prefix' => $prefix,
            'bucket' => $this->bucket,
        ]);
        
        try {
            $fullPrefix = $this->getFullKey($prefix);
            
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $fullPrefix,
            ]);
            
            $files = [];
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $relativePath = $this->getRelativeKey($object['Key']);
                    $files[] = [
                        'path' => $relativePath,
                        'size' => $object['Size'],
                        'modified' => $object['LastModified'],
                    ];
                }
            }
            
            return $files;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list files in S3', [
                'prefix' => $prefix,
                'bucket' => $this->bucket,
                'exception' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $remotePath): bool
    {
        try {
            $key = $this->getFullKey($remotePath);
            return $this->s3Client->doesObjectExist($this->bucket, $key);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check if file exists in S3', [
                'remote_path' => $remotePath,
                'bucket' => $this->bucket,
                'exception' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get the full S3 key for a remote path.
     *
     * @param string $remotePath
     * @return string
     */
    private function getFullKey(string $remotePath): string
    {
        return $this->prefix . ltrim($remotePath, '/');
    }

    /**
     * Get the relative path for a full S3 key.
     *
     * @param string $fullKey
     * @return string
     */
    private function getRelativeKey(string $fullKey): string
    {
        if ($this->prefix === '') {
            return $fullKey;
        }
        
        if (strpos($fullKey, $this->prefix) === 0) {
            return substr($fullKey, strlen($this->prefix));
        }
        
        return $fullKey;
    }
}