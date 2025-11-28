<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->add('_profiler_pro_backup_create', '/_profiler/pro-backup/create')
        ->controller('pro_backup.profiler_controller::create');

    $routes->add('_profiler_pro_backup_restore', '/_profiler/pro-backup/restore')
        ->controller('pro_backup.profiler_controller::restore');

    $routes->add('_profiler_pro_backup_delete', '/_profiler/pro-backup/delete')
        ->controller('pro_backup.profiler_controller::delete');

    $routes->add('_profiler_pro_backup_download', '/_profiler/pro-backup/download')
        ->controller('pro_backup.profiler_controller::download');

    $routes->add('_profiler_pro_backup_list', '/_profiler/pro-backup/list')
        ->controller('pro_backup.profiler_controller::list');
};
