<?php

namespace ProBackupBundle;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ProBackupBundle\DependencyInjection\BackupExtension;
use ProBackupBundle\DependencyInjection\Compiler\StorageAdapterPass;
use ProBackupBundle\DependencyInjection\Compiler\DatabaseAdapterPass;
use ProBackupBundle\DependencyInjection\Compiler\CompressionAdapterPass;

/**
 * BackupBundle provides database and filesystem backup/restore functionality.
 */
class ProBackupBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new BackupExtension();
        }
        
        return $this->extension;
    }
    
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        
        // Register compiler passes
        $container->addCompilerPass(new StorageAdapterPass());
        $container->addCompilerPass(new DatabaseAdapterPass());
        $container->addCompilerPass(new CompressionAdapterPass());
    }
}