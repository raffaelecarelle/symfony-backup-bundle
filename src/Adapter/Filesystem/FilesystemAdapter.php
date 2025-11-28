<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Filesystem;

use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Adapter for filesystem backups.
 */
class FilesystemAdapter implements BackupAdapterInterface
{
    private readonly Filesystem $filesystem;

    /**
     * Constructor.
     */
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->filesystem = new Filesystem();
    }

    public function backup(BackupConfiguration $config): BackupResult
    {
        $startTime = microtime(true);
        $filename = $this->generateFilename($config);
        $outputPath = $config->getOutputPath();
        $filepath = $outputPath . '/' . $filename;

        // Ensure the output directory exists
        if (!$this->filesystem->exists($outputPath)) {
            $this->filesystem->mkdir($outputPath, 0755);
        }

        $this->logger->info('Starting filesystem backup', [
            'output' => $filepath,
        ]);

        try {
            // Create a temporary directory for the files
            $tempDir = sys_get_temp_dir() . '/backup_' . uniqid('', true);
            $this->filesystem->mkdir($tempDir, 0755);

            // Get paths from configuration
            $paths = $config->getOption('paths', []);

            // If no paths in options, try to get them from container parameter
            if (empty($paths) && isset($GLOBALS['kernel'])) {
                $container = $GLOBALS['kernel']->getContainer();
                if ($container->hasParameter('pro_backup.filesystem.paths')) {
                    $paths = $container->getParameter('pro_backup.filesystem.paths');
                }
            }

            if (empty($paths)) {
                throw new \InvalidArgumentException('No paths specified for filesystem backup');
            }

            // Copy files to temporary directory
            foreach ($paths as $pathConfig) {
                $sourcePath = $pathConfig['path'];
                $excludes = $pathConfig['exclude'] ?? [];

                $this->logger->info('Backing up path', [
                    'path' => $sourcePath,
                    'excludes' => $excludes,
                ]);

                // Resolve path if it contains parameters
                if (str_contains((string) $sourcePath, '%')) {
                    $sourcePath = $this->resolvePath($sourcePath);
                }

                if (!$this->filesystem->exists($sourcePath)) {
                    $this->logger->warning('Path does not exist, skipping', [
                        'path' => $sourcePath,
                    ]);
                    continue;
                }

                // Create finder to locate files
                $finder = new Finder();
                $finder->in($sourcePath);

                // Apply exclusions
                foreach ($excludes as $exclude) {
                    $finder->notPath($exclude);
                }

                // Copy files to temporary directory
                foreach ($finder as $file) {
                    $relativePath = $file->getRelativePathname();
                    $targetPath = $tempDir . '/' . $relativePath;

                    if ($file->isDir()) {
                        $this->filesystem->mkdir($targetPath, 0755);
                    } else {
                        $parent = \dirname($targetPath);
                        if (!$this->filesystem->exists($parent)) {
                            $this->filesystem->mkdir($parent, 0755);
                        }

                        $this->filesystem->copy($file->getRealPath(), $targetPath, true);
                    }
                }
            }

            $this->logger->info('Filesystem staging completed (no compression)', [
                'staging_dir' => $tempDir,
                'duration' => microtime(true) - $startTime,
            ]);

            return new BackupResult(
                true,
                $tempDir,
                $this->getDirectorySize($tempDir),
                new \DateTimeImmutable(),
                microtime(true) - $startTime,
                null,
                []
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Filesystem backup failed', [
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            // Clean up any partial files
            if (isset($tempDir) && $this->filesystem->exists($tempDir)) {
                $this->filesystem->remove($tempDir);
            }

            if ($this->filesystem->exists($filepath)) {
                $this->filesystem->remove($filepath);
            }

            return new BackupResult(
                false,
                null,
                null,
                new \DateTimeImmutable(),
                microtime(true) - $startTime,
                $throwable->getMessage()
            );
        }
    }

    /**
     * Calculate the total size of a directory recursively.
     *
     * @param string $path Path to the directory or file
     *
     * @return int Total size in bytes
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return filesize($path);
        }

        $finder = new Finder();
        $finder->files()->in($path);

        $size = 0;
        foreach ($finder as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    public function restore(string $backupPath, array $options = []): bool
    {
        $this->logger->info('Starting filesystem restore', [
            'source' => $backupPath,
        ]);

        try {
            // Here we expect $backupPath to be a directory already extracted by the BackupManager
            if (!$this->filesystem->exists($backupPath) || !is_dir($backupPath)) {
                throw new \InvalidArgumentException('Provided backup path must be an extracted directory');
            }

            // Get target directory from options or use default
            $targetDir = $options['target_dir'] ?? null;
            if (!$targetDir) {
                throw new \InvalidArgumentException('Target directory not specified for restore');
            }

            // Ensure target directory exists
            if (!$this->filesystem->exists($targetDir)) {
                $this->filesystem->mkdir($targetDir, 0755);
            }

            // Copy files from extracted directory to target directory
            $finder = new Finder();
            $finder->in($backupPath);

            foreach ($finder as $file) {
                $relativePath = $file->getRelativePathname();
                $targetPath = $targetDir . '/' . $relativePath;

                if ($file->isDir()) {
                    $this->filesystem->mkdir($targetPath, 0755);
                } else {
                    $parent = \dirname($targetPath);
                    if (!$this->filesystem->exists($parent)) {
                        $this->filesystem->mkdir($parent, 0755);
                    }

                    $this->filesystem->copy($file->getRealPath(), $targetPath, true);
                }
            }

            $this->logger->info('Filesystem restore completed', [
                'target_dir' => $targetDir,
            ]);

            return true;
        } catch (\Throwable $throwable) {
            $this->logger->error('Filesystem restore failed', [
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function supports(string $type): bool
    {
        return 'filesystem' === $type;
    }

    public function validate(BackupConfiguration $config): array
    {
        $errors = [];

        if (!$config->getOutputPath()) {
            $errors[] = 'Output path is not specified';
        }

        $paths = $config->getOption('paths', []);
        if (empty($paths)) {
            $errors[] = 'No paths specified for filesystem backup';
        }

        return $errors;
    }

    /**
     * Generate a filename for the backup.
     */
    private function generateFilename(BackupConfiguration $config): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $name = $config->getName() ?: 'filesystem';
        $extension = 'zip' === $config->getCompression() ? 'zip' : 'tar.gz';

        return \sprintf('%s_%s.%s', $name, $timestamp, $extension);
    }

    /**
     * Resolve a path with parameters.
     *
     * @param string $path Path with parameters
     *
     * @return string Resolved path
     */
    private function resolvePath(string $path): string
    {
        // Simple parameter resolution for %kernel.project_dir%
        if (str_contains($path, '%kernel.project_dir%')) {
            $projectDir = \dirname(__DIR__, 3); // Assuming src is 2 levels deep from project root

            return str_replace('%kernel.project_dir%', $projectDir, $path);
        }

        return $path;
    }
}
