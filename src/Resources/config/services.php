<?php

declare(strict_types=1);

use ProBackupBundle\Adapter\Database\DatabaseAdapterFactory;
use ProBackupBundle\Command\BackupCommand;
use ProBackupBundle\Command\ListCommand;
use ProBackupBundle\Command\PurgeCommand;
use ProBackupBundle\Command\RestoreCommand;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Process\Factory\ProcessFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set('pro_backup.manager', BackupManager::class)
        ->arg('$backupDir', '%kernel.project_dir%/var/backups')
        ->arg('$eventDispatcher', service('event_dispatcher')->nullOnInvalid())
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->arg('$doctrine', service('doctrine')->nullOnInvalid())->public();

    $services->alias(BackupManager::class, 'pro_backup.manager')->public();

    $services->set('pro_backup.command.backup', BackupCommand::class)
        ->arg('$backupManager', service('pro_backup.manager'))
        ->arg('$config', '%pro_backup.config%')
        ->tag('console.command');

    $services->set('pro_backup.command.restore', RestoreCommand::class)
        ->arg('$backupManager', service('pro_backup.manager'))
        ->tag('console.command');

    $services->set('pro_backup.command.list', ListCommand::class)
        ->arg('$backupManager', service('pro_backup.manager'))
        ->tag('console.command');

    $services->set('pro_backup.command.purge', PurgeCommand::class)
        ->arg('$backupManager', service('pro_backup.manager'))
        ->tag('console.command');

    $services->set('pro_backup.process.factory', ProcessFactory::class);

    $services->set('pro_backup.database.adapter_factory', DatabaseAdapterFactory::class)
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->arg('$processFactory', service('pro_backup.process.factory')->nullOnInvalid());
};
