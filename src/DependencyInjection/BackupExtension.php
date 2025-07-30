<?php

declare(strict_types=1);

namespace ProBackupBundle\DependencyInjection;

use ProBackupBundle\Adapter\Compression\GzipCompression;
use ProBackupBundle\Adapter\Compression\ZipCompression;
use ProBackupBundle\Adapter\Database\MySQLAdapter;
use ProBackupBundle\Adapter\Database\PostgreSQLAdapter;
use ProBackupBundle\Adapter\Database\SQLiteAdapter;
use ProBackupBundle\Adapter\Database\SqlServerAdapter;
use ProBackupBundle\Adapter\Storage\GoogleCloudAdapter;
use ProBackupBundle\Adapter\Storage\LocalAdapter;
use ProBackupBundle\Adapter\Storage\S3Adapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class BackupExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        // Configure the backup manager
        $backupManagerDef = $container->getDefinition('symfony_backup.manager');
        $backupManagerDef->setArgument(0, $config['backup_dir']);
        $backupManagerDef->setArgument(1, new Reference('event_dispatcher'));

        // Configure storage adapters
        $this->configureStorageAdapters($container, $config);

        // Configure database adapters
        $this->configureDatabaseAdapters($container, $config);

        // Configure compression adapters
        $this->configureCompressionAdapters($container, $config);

        // Configure profiler integration
        if ($config['profiler']['enabled']) {
            $loader->load('profiler.xml');
        }

        // Configure scheduler
        if ($config['schedule']['enabled']) {
            $loader->load('scheduler.xml');

            $schedulerDef = $container->getDefinition('symfony_backup.scheduler');
            $schedulerDef->setArgument(1, $config['schedule']);
        }

        // Set parameters
        $container->setParameter('symfony_backup.config', $config);
    }

    /**
     * Configure storage adapters.
     */
    private function configureStorageAdapters(ContainerBuilder $container, array $config): void
    {
        // Configure local storage adapter (sempre configurato come fallback)
        $localConfig = $config['storage']['local'] ?? ['options' => ['path' => $config['backup_dir']]];
        $localDef = $container->register('symfony_backup.storage.local', LocalAdapter::class);
        $localDef->setArguments([
            $localConfig['options']['path'],
            $localConfig['options']['permissions'] ?? 0755,
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $localDef->addTag('symfony_backup.storage_adapter', ['name' => 'local']);

        // Configure S3 storage adapter SOLO se esplicitamente definito e abilitato
        if (isset($config['storage']['s3']) && \is_array($config['storage']['s3'])
            && ($config['storage']['s3']['enabled'] ?? true)) {
            $s3Config = $config['storage']['s3'];

            // Check if AWS SDK is available
            if (!class_exists('Aws\S3\S3Client')) {
                throw new \RuntimeException('AWS SDK is not installed. Run "composer require aws/aws-sdk-php".');
            }

            // Create S3 client
            $s3ClientDef = $container->register('symfony_backup.aws_s3_client', 'Aws\S3\S3Client');
            $s3ClientDef->setFactory(['Aws\S3\S3Client', 'factory']);
            $s3ClientDef->setArguments([[
                'version' => 'latest',
                'region' => $s3Config['options']['region'],
                'credentials' => [
                    'key' => $s3Config['options']['credentials']['key'],
                    'secret' => $s3Config['options']['credentials']['secret'],
                ],
            ]]);

            // Create S3 adapter
            $s3Def = $container->register('symfony_backup.storage.s3', S3Adapter::class);
            $s3Def->setArguments([
                new Reference('symfony_backup.aws_s3_client'),
                $s3Config['options']['bucket'],
                $s3Config['options']['prefix'] ?? '',
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $s3Def->addTag('symfony_backup.storage_adapter', ['name' => 's3']);
        }

        // Configure Google Cloud storage adapter SOLO se esplicitamente definito e abilitato
        if (isset($config['storage']['google_cloud']) && \is_array($config['storage']['google_cloud'])
            && ($config['storage']['google_cloud']['enabled'] ?? true)) {
            $gcConfig = $config['storage']['google_cloud'];

            // Check if Google Cloud SDK is available
            if (!class_exists('Google\Cloud\Storage\StorageClient')) {
                throw new \RuntimeException('Google Cloud SDK is not installed. Run "composer require google/cloud-storage".');
            }

            // Create Google Cloud client
            $gcClientDef = $container->register('symfony_backup.google_cloud_client', 'Google\Cloud\Storage\StorageClient');
            $gcClientDef->setArguments([[
                'projectId' => $gcConfig['options']['project_id'],
                'keyFilePath' => $gcConfig['options']['key_file'],
            ]]);

            // Create Google Cloud adapter
            $gcDef = $container->register('symfony_backup.storage.google_cloud', GoogleCloudAdapter::class);
            $gcDef->setArguments([
                new Reference('symfony_backup.google_cloud_client'),
                $gcConfig['options']['bucket'],
                $gcConfig['options']['prefix'] ?? '',
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $gcDef->addTag('symfony_backup.storage_adapter', ['name' => 'google_cloud']);

            // Set default storage
            $container->getDefinition('symfony_backup.manager')
                ->addMethodCall('setDefaultStorage', [$config['default_storage']]);
        }
    }

    /**
     * Configure database adapters.
     */
    private function configureDatabaseAdapters(ContainerBuilder $container, array $config): void
    {
        if (!$config['database']['enabled']) {
            return;
        }

        // Get Doctrine connections
        $connections = $config['database']['connections'];

        foreach ($connections as $connectionName) {
            $connectionServiceId = 'doctrine.dbal.'.$connectionName.'_connection';

            // MySQL adapter
            $mysqlDef = $container->register('symfony_backup.database.mysql.'.$connectionName, MySQLAdapter::class);
            $mysqlDef->setArguments([
                new Reference($connectionServiceId),
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $mysqlDef->addTag('symfony_backup.database_adapter');

            // PostgreSQL adapter
            $pgDef = $container->register('symfony_backup.database.postgresql.'.$connectionName, PostgreSQLAdapter::class);
            $pgDef->setArguments([
                new Reference($connectionServiceId),
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $pgDef->addTag('symfony_backup.database_adapter');

            // SQLite adapter
            $sqliteDef = $container->register('symfony_backup.database.sqlite.'.$connectionName, SQLiteAdapter::class);
            $sqliteDef->setArguments([
                new Reference($connectionServiceId),
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $sqliteDef->addTag('symfony_backup.database_adapter');

            // SQL Server adapter
            $sqlServerDef = $container->register('symfony_backup.database.sqlserver.'.$connectionName, SqlServerAdapter::class);
            $sqlServerDef->setArguments([
                new Reference($connectionServiceId),
                new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $sqlServerDef->addTag('symfony_backup.database_adapter');
        }
    }

    /**
     * Configure compression adapters.
     */
    private function configureCompressionAdapters(ContainerBuilder $container, array $config): void
    {
        // Gzip compression
        $gzipDef = $container->register('symfony_backup.compression.gzip', GzipCompression::class);
        $gzipDef->setArguments([
            $config['compression']['gzip']['level'] ?? 6,
            $config['compression']['gzip']['keep_original'] ?? false,
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $gzipDef->addTag('symfony_backup.compression_adapter', ['name' => 'gzip']);

        // Zip compression
        $zipDef = $container->register('symfony_backup.compression.zip', ZipCompression::class);
        $zipDef->setArguments([
            $config['compression']['zip']['level'] ?? 6,
            $config['compression']['zip']['keep_original'] ?? false,
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $zipDef->addTag('symfony_backup.compression_adapter', ['name' => 'zip']);
    }

    public function getAlias(): string
    {
        return 'symfony_backup';
    }
}
