<?php

declare(strict_types=1);

namespace TestApp;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir().'/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if ((isset($envs['all']) || isset($envs[$this->environment])) && (\is_string($class) && is_subclass_of($class, BundleInterface::class))) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $confDir = $this->getProjectDir().'/config';
        $loader->load($confDir.'/packages/*.{php,xml,yaml,yml}', 'glob');
        $loader->load($confDir.'/packages/'.$this->environment.'/**/*.{php,xml,yaml,yml}', 'glob');
        $loader->load($confDir.'/services.{php,xml,yaml,yml}', 'glob');
        $loader->load($confDir.'/services_'.$this->environment.'.{php,xml,yaml,yml}', 'glob');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $confDir = $this->getProjectDir().'/config';
        $routes->import($confDir.'/routes/*.{php,xml,yaml,yml}', 'glob');
        $routes->import($confDir.'/routes/'.$this->environment.'/**/*.{php,xml,yaml,yml}', 'glob');
        $routes->import($confDir.'/routes.{php,xml,yaml,yml}', 'glob');
    }
}
