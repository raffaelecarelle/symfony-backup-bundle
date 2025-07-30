<?php

namespace ProBackupBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass to register compression adapters with the backup manager.
 */
class CompressionAdapterPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('symfony_backup.manager')) {
            return;
        }

        $managerDefinition = $container->getDefinition('symfony_backup.manager');
        $taggedServices = $container->findTaggedServiceIds('symfony_backup.compression_adapter');

        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                $name = $attributes['name'] ?? $this->getNameFromServiceId($id);
                $managerDefinition->addMethodCall('addCompressionAdapter', [
                    $name,
                    new Reference($id),
                ]);
            }
        }
    }

    /**
     * Extract a name from a service ID.
     *
     * @param string $serviceId
     * @return string
     */
    private function getNameFromServiceId(string $serviceId): string
    {
        // Extract the last part of the service ID
        if (preg_match('/\.([^.]+)$/', $serviceId, $matches)) {
            return $matches[1];
        }

        return $serviceId;
    }
}