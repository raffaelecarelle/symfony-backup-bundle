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
        if (!$container->hasDefinition('symfony_backup.manager')) {
            return;
        }

        $managerDefinition = $container->getDefinition('symfony_backup.manager');
        $taggedServices = $container->findTaggedServiceIds('symfony_backup.database_adapter');

        foreach ($taggedServices as $id => $tags) {
            $managerDefinition->addMethodCall('addAdapter', [
                new Reference($id),
            ]);
        }
    }
}
