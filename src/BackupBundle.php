<?php

namespace Symfony\Component\Backup;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Backup\DependencyInjection\BackupExtension;
use Symfony\Component\Backup\DependencyInjection\Compiler\StorageAdapterPass;
use Symfony\Component\Backup\DependencyInjection\Compiler\DatabaseAdapterPass;
use Symfony\Component\Backup\DependencyInjection\Compiler\CompressionAdapterPass;

/**
 * BackupBundle provides database and filesystem backup/restore functionality.
 */
class BackupBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getContainerExtension()
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