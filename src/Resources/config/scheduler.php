<?php

declare(strict_types=1);

use ProBackupBundle\Scheduler\BackupScheduler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set('pro_backup.scheduler', BackupScheduler::class)
        ->arg('$scheduleConfig', []) // This will be set by the extension
        ->tag('scheduler.task_source')->public();
};
