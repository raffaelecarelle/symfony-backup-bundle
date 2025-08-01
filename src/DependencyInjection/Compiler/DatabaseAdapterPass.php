<?php

declare(strict_types=1);

namespace ProBackupBundle\DependencyInjection\Compiler;

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

        $managerDefinition = $container->getDefinition('pro_backup.manager');
        $taggedServices = $container->findTaggedServiceIds('pro_backup.database_adapter');

        foreach ($taggedServices as $id => $tags) {
            $managerDefinition->addMethodCall('addAdapter', [
                new Reference($id),
            ]);
        }
    }
}
