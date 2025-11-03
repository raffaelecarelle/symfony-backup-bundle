<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Filesystem;

use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Adapter\Compression\CompressionAdapterInterface;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Adapter for filesystem backups.
 */
class FilesystemAdapter implements BackupAdapterInterface
{
    private readonly LoggerInterface $logger;

    private readonly Filesystem $filesystem;

    private readonly CompressionAdapterInterface $compressionAdapter;

    /**
     * Constructor.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->filesystem = new Filesystem();
    }

    public function setCompressionAdapter(CompressionAdapterInterface $compressionAdapter): self
    {
        $this->compressionAdapter = $compressionAdapter;

        return $this;
    }

    public function backup(BackupConfiguration $config): BackupResult
    {
        $startTime = microtime(true);
        $filename = $this->generateFilename($config);
        $outputPath = $config->getOutputPath();
        $filepath = $outputPath.'/'.$filename;

        // Ensure the output directory exists
        if (!$this->filesystem->exists($outputPath)) {
            $this->filesystem->mkdir($outputPath, 0755);
        }

        $this->logger->info('Starting filesystem backup', [
            'output' => $filepath,
        ]);

        try {
            // Create a temporary directory for the files
            $tempDir = sys_get_temp_dir().'/backup_'.uniqid('', true);
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
                    $targetPath = $tempDir.'/'.basename((string) $sourcePath).'/'.$relativePath;

                    if ($file->isDir()) {
                        $this->filesystem->mkdir($targetPath, 0755);
                    } else {
                        $this->filesystem->copy($file->getRealPath(), $targetPath, true);
                    }
                }
            }

            $archivePath = $this->compressionAdapter->compress($tempDir, $filepath);

            // Clean up temporary directory
            $this->filesystem->remove($tempDir);

            $this->logger->info('Filesystem backup completed', [
                'file' => $archivePath,
                'size' => filesize($archivePath),
                'duration' => microtime(true) - $startTime,
            ]);

            return new BackupResult(
                true,
                $archivePath,
                filesize($archivePath),
                new \DateTimeImmutable(),
                microtime(true) - $startTime,
                null,
                ['compression' => $config->getCompression()]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Filesystem backup failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial files
            if (isset($tempDir) && $this->filesystem->exists($tempDir)) {
                $this->filesystem->remove($tempDir);
            }

            if (isset($filepath) && $this->filesystem->exists($filepath)) {
                $this->filesystem->remove($filepath);
            }

            return new BackupResult(
                false,
                null,
                null,
                new \DateTimeImmutable(),
                microtime(true) - $startTime,
                $e->getMessage()
            );
        }
    }

    public function restore(string $backupPath, array $options = []): bool
    {
        $this->logger->info('Starting filesystem restore', [
            'file' => $backupPath,
        ]);

        try {
            // Create a temporary directory for extraction
            $tempDir = sys_get_temp_dir().'/restore_'.uniqid('', true);
            $this->filesystem->mkdir($tempDir, 0755);

            // Extract the archive
            $extension = pathinfo($backupPath, \PATHINFO_EXTENSION);
            $this->extractArchive($backupPath, $tempDir, $extension);

            // Get target directory from options or use default
            $targetDir = $options['target_dir'] ?? null;
            if (!$targetDir) {
                throw new \InvalidArgumentException('Target directory not specified for restore');
            }

            // Ensure target directory exists
            if (!$this->filesystem->exists($targetDir)) {
                $this->filesystem->mkdir($targetDir, 0755);
            }

            // Copy files from temporary directory to target directory
            $finder = new Finder();
            $finder->in($tempDir);

            foreach ($finder as $file) {
                $relativePath = $file->getRelativePathname();
                $targetPath = $targetDir.'/'.$relativePath;

                if ($file->isDir()) {
                    $this->filesystem->mkdir($targetPath, 0755);
                } else {
                    $this->filesystem->copy($file->getRealPath(), $targetPath, true);
                }
            }

            // Clean up temporary directory
            $this->filesystem->remove($tempDir);

            $this->logger->info('Filesystem restore completed', [
                'target_dir' => $targetDir,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Filesystem restore failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up temporary directory
            if (isset($tempDir) && $this->filesystem->exists($tempDir)) {
                $this->filesystem->remove($tempDir);
            }

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
     * Create an archive of the specified directory.
     *
     * @param string $sourceDir   Directory to archive
     * @param string $targetPath  Path where the archive should be created
     * @param string $compression Compression type ('zip' or 'gzip')
     *
     * @return string Path to the created archive
     *
     * @throws ProcessFailedException If the archive creation fails
     */
    private function createArchive(string $sourceDir, string $targetPath, string $compression): string
    {
        if ('zip' === $compression) {
            $command = \sprintf('cd %s && zip -r %s .', escapeshellarg($sourceDir), escapeshellarg($targetPath));
        } else {
            $command = \sprintf('cd %s && tar -czf %s .', escapeshellarg($sourceDir), escapeshellarg($targetPath));
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $targetPath;
    }

    /**
     * Extract an archive to the specified directory.
     *
     * @param string $archivePath Path to the archive
     * @param string $targetDir   Directory where the archive should be extracted
     * @param string $extension   File extension of the archive
     *
     * @throws ProcessFailedException If the extraction fails
     */
    private function extractArchive(string $archivePath, string $targetDir, string $extension): void
    {
        if ('zip' === $extension) {
            $command = \sprintf('unzip %s -d %s', escapeshellarg($archivePath), escapeshellarg($targetDir));
        } else {
            $command = \sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($targetDir));
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
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
