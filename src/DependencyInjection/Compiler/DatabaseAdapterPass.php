<?php

declare(strict_types=1);

namespace ProBackupBundle\DependencyInjection\Compiler;

use ProBackupBundle\Adapter\BackupAdapterInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass to register database adapters with the backup manager.
 */
class DatabaseAdapterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('pro_backup.manager')) {
            return;
        }

        // Register database adapters based on Doctrine connection drivers
        $this->registerDatabaseAdapters($container);

        // Add all tagged adapters to the backup manager
        $managerDefinition = $container->getDefinition('pro_backup.manager');
        $taggedServices = $container->findTaggedServiceIds('pro_backup.database_adapter');

        foreach (array_keys($taggedServices) as $id) {
            $managerDefinition->addMethodCall('addAdapter', [
                new Reference($id),
            ]);
        }
    }

    private function registerDatabaseAdapters(ContainerBuilder $container): void
    {
        $config = $container->getParameter('pro_backup.config');

        if (!$config['database']['enabled']) {
            return;
        }

        $connections = $config['database']['connections'];

        foreach ($connections as $connectionName) {
            $connectionServiceId = 'doctrine.dbal.' . $connectionName . '_connection';

            if (!$container->has($connectionServiceId)) {
                continue;
            }

            // Register adapter using factory pattern
            $this->registerAdapterViaFactory($container, $connectionName, $connectionServiceId);
        }
    }

    private function registerAdapterViaFactory(
        ContainerBuilder $container,
        string $connectionName,
        string $connectionServiceId,
    ): void {
        $adapterServiceId = 'pro_backup.database.adapter.' . $connectionName;

        if ($container->has($adapterServiceId)) {
            return;
        }

        // Register the adapter using the factory
        $adapterDef = $container->register($adapterServiceId, BackupAdapterInterface::class);
        $adapterDef->setFactory([
            new Reference('pro_backup.database.adapter_factory'),
            'createAdapter',
        ]);
        $adapterDef->setArguments([
            new Reference($connectionServiceId),
        ]);
        $adapterDef->addTag('pro_backup.database_adapter');
    }
}
