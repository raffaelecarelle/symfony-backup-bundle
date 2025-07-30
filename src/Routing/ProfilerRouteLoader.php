<?php

declare(strict_types=1);

namespace ProBackupBundle\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loader for profiler routes.
 */
class ProfilerRouteLoader extends Loader
{
    /**
     * Load routes for the profiler.
     */
    public function load(mixed $resource, ?string $type = null): mixed
    {
        $routes = new RouteCollection();

        // Create backup
        $routes->add('_profiler_backup_create', new Route(
            '/_profiler/backup/create',
            [
                '_controller' => 'symfony_backup.profiler_controller:create',
            ],
            [],
            [],
            '',
            [],
            ['POST']
        ));

        // Restore backup
        $routes->add('_profiler_backup_restore', new Route(
            '/_profiler/backup/restore',
            [
                '_controller' => 'symfony_backup.profiler_controller:restore',
            ],
            [],
            [],
            '',
            [],
            ['POST']
        ));

        // Download backup
        $routes->add('_profiler_backup_download', new Route(
            '/_profiler/backup/download',
            [
                '_controller' => 'symfony_backup.profiler_controller:download',
            ],
            [],
            [],
            '',
            [],
            ['GET']
        ));

        // Delete backup
        $routes->add('_profiler_backup_delete', new Route(
            '/_profiler/backup/delete',
            [
                '_controller' => 'symfony_backup.profiler_controller:delete',
            ],
            [],
            [],
            '',
            [],
            ['DELETE']
        ));

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return true;
    }
}
