<?php

namespace ProBackupBundle\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class ProfilerRouteLoader extends Loader
{
    private bool $isLoaded = false;

    public function load($resource, string $type = null): RouteCollection
    {
        if (true === $this->isLoaded) {
            throw new \RuntimeException('Do not add the "extra" loader twice');
        }

        $routes = new RouteCollection();

        // Aggiungi le tue route per il profiler qui
        $route = new Route(
            '/_profiler/backup/{token}',
            ['_controller' => 'symfony_backup.profiler_controller::backupAction']
        );
        $routes->add('_profiler_backup', $route);

        $this->isLoaded = true;

        return $routes;
    }

    public function supports($resource, string $type = null): bool
    {
        return 'extra' === $type;
    }
}