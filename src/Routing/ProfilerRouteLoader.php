<?php

namespace Symfony\Component\Backup\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loader for profiler routes.
 */
class ProfilerRouteLoader
{
    /**
     * Load routes for the profiler.
     *
     * @return RouteCollection
     */
    public static function loadRoutes(): RouteCollection
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
}