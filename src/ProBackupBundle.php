<?php

declare(strict_types=1);

namespace ProBackupBundle;

use ProBackupBundle\DependencyInjection\BackupExtension;
use ProBackupBundle\DependencyInjection\Compiler\CompressionAdapterPass;
use ProBackupBundle\DependencyInjection\Compiler\DatabaseAdapterPass;
use ProBackupBundle\DependencyInjection\Compiler\StorageAdapterPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * BackupBundle provides database and filesystem backup/restore functionality.
 */
class ProBackupBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new BackupExtension();
        }

        return $this->extension;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register compiler passes
        $container->addCompilerPass(new StorageAdapterPass());
        $container->addCompilerPass(new DatabaseAdapterPass());
        $container->addCompilerPass(new CompressionAdapterPass());
    }
}
