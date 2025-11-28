<?php

declare(strict_types=1);

namespace ProBackupBundle\DependencyInjection;

use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
use ProBackupBundle\Adapter\Compression\GzipCompression;
use ProBackupBundle\Adapter\Compression\ZipCompression;
use ProBackupBundle\Adapter\Filesystem\FilesystemAdapter;
use ProBackupBundle\Adapter\Storage\GoogleCloudAdapter;
use ProBackupBundle\Adapter\Storage\LocalAdapter;
use ProBackupBundle\Adapter\Storage\S3Adapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ProBackupExtension extends Extension
{
    /**
     * @param array<int, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        // Configure the backup manager
        $backupManagerDef = $container->getDefinition('pro_backup.manager');
        $backupManagerDef->setArgument(0, $config['backup_dir']);
        $backupManagerDef->setArgument(1, new Reference('event_dispatcher'));

        // Configure storage adapters
        $this->configureStorageAdapters($container, $config);

        // Configure database adapters
        $this->configureDatabaseAdapters();

        // Configure filesystem adapter
        $this->configureFilesystemAdapter($container, $config);

        // Configure compression adapters
        $this->configureCompressionAdapters($container, $config);

        // Configure profiler integration
        if ($config['profiler']['enabled']) {
            $loader->load('profiler.xml');
        }

        // Configure scheduler
        if ($config['schedule']['enabled']) {
            $loader->load('scheduler.xml');

            $schedulerDef = $container->getDefinition('pro_backup.scheduler');
            // Fix: the provider constructor takes a single argument (the schedule config)
            $schedulerDef->setArgument(0, $config['schedule']);
        }

        // Always set default storage on manager after adapters are registered
        $container->getDefinition('pro_backup.manager')
            ->addMethodCall('setDefaultStorage', [$config['default_storage']])
            ->addMethodCall('setConfig', [$config]);

        // Set parameters
        $container->setParameter('pro_backup.config', $config);
    }

    /**
     * Configure storage adapters.
     */
    /**
     * @param array<string, mixed> $config
     */
    private function configureStorageAdapters(ContainerBuilder $container, array $config): void
    {
        // Configure local storage adapter (sempre configurato come fallback)
        $localConfig = $config['storage']['local'] ?? ['options' => ['path' => $config['backup_dir']]];
        $localDef = $container->register('pro_backup.storage.local', LocalAdapter::class);
        $localDef->setArguments([
            $localConfig['options']['path'],
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            $localConfig['options']['permissions'] ?? 0755,
        ]);
        $localDef->addTag('pro_backup.storage_adapter', ['name' => 'local']);

        // Configure S3 storage adapter SOLO se esplicitamente definito e abilitato
        if (isset($config['storage']['s3']) && \is_array($config['storage']['s3'])
            && ($config['storage']['s3']['enabled'] ?? true)) {
            $s3Config = $config['storage']['s3'];

            // Check if AWS SDK is available
            if (!class_exists(S3Client::class)) {
                throw new \RuntimeException('AWS SDK is not installed. Run "composer require aws/aws-sdk-php".');
            }

            // Create S3 client
            $s3ClientDef = $container->register('pro_backup.aws_s3_client', S3Client::class);
            $s3ClientDef->setFactory([S3Client::class, 'factory']);
            $s3ClientDef->setArguments([[
                'version' => 'latest',
                'region' => $s3Config['options']['region'],
                'credentials' => [
                    'key' => $s3Config['options']['credentials']['key'],
                    'secret' => $s3Config['options']['credentials']['secret'],
                ],
            ]]);

            // Create S3 adapter
            $s3Def = $container->register('pro_backup.storage.s3', S3Adapter::class);
            $s3Def->setArguments([
                new Reference('pro_backup.aws_s3_client'),
                $s3Config['options']['bucket'],
                $s3Config['options']['prefix'] ?? '',
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $s3Def->addTag('pro_backup.storage_adapter', ['name' => 's3']);
        }

        // Configure Google Cloud storage adapter SOLO se esplicitamente definito e abilitato
        if (isset($config['storage']['google_cloud']) && \is_array($config['storage']['google_cloud'])
            && ($config['storage']['google_cloud']['enabled'] ?? true)) {
            $gcConfig = $config['storage']['google_cloud'];

            // Check if Google Cloud SDK is available
            if (!class_exists(StorageClient::class)) {
                throw new \RuntimeException('Google Cloud SDK is not installed. Run "composer require google/cloud-storage".');
            }

            // Create Google Cloud client
            $gcClientDef = $container->register('pro_backup.google_cloud_client', StorageClient::class);
            $gcClientDef->setArguments([[
                'projectId' => $gcConfig['options']['project_id'],
                'keyFilePath' => $gcConfig['options']['key_file'],
            ]]);

            // Create Google Cloud adapter
            $gcDef = $container->register('pro_backup.storage.google_cloud', GoogleCloudAdapter::class);
            $gcDef->setArguments([
                new Reference('pro_backup.google_cloud_client'),
                $gcConfig['options']['bucket'],
                $gcConfig['options']['prefix'] ?? '',
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $gcDef->addTag('pro_backup.storage_adapter', ['name' => 'google_cloud']);

            // Set default storage
            $container->getDefinition('pro_backup.manager')
                ->addMethodCall('setDefaultStorage', [$config['default_storage']]);
        }
    }

    private function configureDatabaseAdapters(): void
    {
        // Database adapter registration is handled by DatabaseAdapterPass compiler pass
        // which has access to the actual Doctrine connection configuration
    }

    /**
     * Configure filesystem adapter.
     */
    /**
     * @param array<string, mixed> $config
     */
    private function configureFilesystemAdapter(ContainerBuilder $container, array $config): void
    {
        if (!$config['filesystem']['enabled']) {
            return;
        }

        // Register filesystem adapter
        $filesystemDef = $container->register('pro_backup.adapter.filesystem', FilesystemAdapter::class);
        $filesystemDef->setArguments([
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $filesystemDef->addTag('pro_backup.database_adapter');
        //        $filesystemDef->addMethodCall('setCompressionAdapter', [new Reference('pro_backup.compression.'.$config['filesystem']['compression'])]);

        // Set paths in options
        $container->setParameter('pro_backup.filesystem.paths', $config['filesystem']['paths']);
    }

    /**
     * Configure compression adapters.
     */
    /**
     * @param array<string, mixed> $config
     */
    private function configureCompressionAdapters(ContainerBuilder $container, array $config): void
    {
        // Gzip compression
        $gzipDef = $container->register('pro_backup.compression.gzip', GzipCompression::class);
        $gzipDef->setArguments([
            $config['compression']['gzip']['level'] ?? 6,
            $config['compression']['gzip']['keep_original'] ?? false,
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $gzipDef->addTag('pro_backup.compression_adapter', ['name' => 'gzip']);

        // Zip compression
        $zipDef = $container->register('pro_backup.compression.zip', ZipCompression::class);
        $zipDef->setArguments([
            $config['compression']['zip']['level'] ?? 6,
            $config['compression']['zip']['keep_original'] ?? false,
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $zipDef->addTag('pro_backup.compression_adapter', ['name' => 'zip']);
    }

    public function getAlias(): string
    {
        return 'pro_backup';
    }
}
