<?php

namespace Symfony\Component\Backup\Adapter\Compression;

/**
 * Interface for compression adapters.
 */
interface CompressionAdapterInterface
{
    /**
     * Compress a file.
     *
     * @param string $sourcePath Path to the file to compress
     * @param string|null $targetPath Path where the compressed file should be stored (if null, will use sourcePath + extension)
     * @param array $options Additional options for the compression
     * 
     * @return string Path to the compressed file
     * 
     * @throws \Symfony\Component\Backup\Exception\BackupException If compression fails
     */
    public function compress(string $sourcePath, ?string $targetPath = null, array $options = []): string;
    
    /**
     * Decompress a file.
     *
     * @param string $sourcePath Path to the file to decompress
     * @param string|null $targetPath Path where the decompressed file should be stored (if null, will use sourcePath without extension)
     * @param array $options Additional options for the decompression
     * 
     * @return string Path to the decompressed file
     * 
     * @throws \Symfony\Component\Backup\Exception\BackupException If decompression fails
     */
    public function decompress(string $sourcePath, ?string $targetPath = null, array $options = []): string;
    
    /**
     * Check if this adapter supports the given file.
     *
     * @param string $filePath Path to the file
     * 
     * @return bool True if supported, false otherwise
     */
    public function supports(string $filePath): bool;
    
    /**
     * Get the file extension used by this compression adapter.
     *
     * @return string File extension (e.g., 'gz', 'zip')
     */
    public function getExtension(): string;
}