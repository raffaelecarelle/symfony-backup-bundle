<?php

namespace ProBackupBundle\Adapter\Storage;

/**
 * Interface for storage adapters.
 */
interface StorageAdapterInterface
{
    /**
     * Store a file from local path to remote storage.
     *
     * @param string $localPath Path to the local file
     * @param string $remotePath Path where the file should be stored remotely
     * 
     * @return bool True if the file was stored successfully, false otherwise
     */
    public function store(string $localPath, string $remotePath): bool;
    
    /**
     * Retrieve a file from remote storage to local path.
     *
     * @param string $remotePath Path to the remote file
     * @param string $localPath Path where the file should be stored locally
     * 
     * @return bool True if the file was retrieved successfully, false otherwise
     */
    public function retrieve(string $remotePath, string $localPath): bool;
    
    /**
     * Delete a file from remote storage.
     *
     * @param string $remotePath Path to the remote file
     * 
     * @return bool True if the file was deleted successfully, false otherwise
     */
    public function delete(string $remotePath): bool;
    
    /**
     * List files in remote storage.
     *
     * @param string $prefix Optional prefix to filter files
     * 
     * @return array List of files in the remote storage
     */
    public function list(string $prefix = ''): array;
    
    /**
     * Check if a file exists in remote storage.
     *
     * @param string $remotePath Path to the remote file
     * 
     * @return bool True if the file exists, false otherwise
     */
    public function exists(string $remotePath): bool;
}