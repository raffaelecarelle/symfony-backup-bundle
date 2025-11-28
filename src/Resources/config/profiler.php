<?php

declare(strict_types=1);

use ProBackupBundle\Controller\ProfilerBackupController;
use ProBackupBundle\DataCollector\BackupDataCollector;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set('pro_backup.data_collector', BackupDataCollector::class)
        ->arg('$backupManager', service('pro_backup.manager'))
        ->tag('data_collector', [
            'template' => '@ProBackup/Collector/backup.html.twig',
            'id' => 'backup',
            'priority' => 200,
        ])->public();

    $services->set('pro_backup.profiler_controller', ProfilerBackupController::class)
        ->arg('$backupManager', service('pro_backup.manager'))
        ->arg('$backupDataCollector', service('pro_backup.data_collector'))->public();
};
