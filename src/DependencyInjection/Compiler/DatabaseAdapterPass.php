<?php

declare(strict_types=1);

namespace ProBackupBundle\DependencyInjection\Compiler;

use ProBackupBundle\Adapter\Database\MySQLAdapter;
use ProBackupBundle\Adapter\Database\PostgreSQLAdapter;
use ProBackupBundle\Adapter\Database\SQLiteAdapter;
use ProBackupBundle\Adapter\Database\SqlServerAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass to register database adapters with the backup manager.
 */
class DatabaseAdapterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('pro_backup.manager')) {
            return;
        }

        // Register database adapters based on Doctrine connection drivers
        $this->registerDatabaseAdapters($container);

        // Add all tagged adapters to the backup manager
        $managerDefinition = $container->getDefinition('pro_backup.manager');
        $taggedServices = $container->findTaggedServiceIds('pro_backup.database_adapter');

        foreach ($taggedServices as $id => $tags) {
            $managerDefinition->addMethodCall('addAdapter', [
                new Reference($id),
            ]);
        }
    }

    private function registerDatabaseAdapters(ContainerBuilder $container): void
    {
        $config = $container->getParameter('pro_backup.config');

        if (!$config['database']['enabled']) {
            return;
        }

        $connections = $config['database']['connections'];

        foreach ($connections as $connectionName) {
            $connectionServiceId = 'doctrine.dbal.'.$connectionName.'_connection';

            if (!$container->has($connectionServiceId)) {
                continue;
            }

            $connectionDefinition = $container->getDefinition($connectionServiceId);

            $arguments = $connectionDefinition->getArguments();

            $driver = null;
            if (isset($arguments[0]) && is_array($arguments[0])) {
                $params = $arguments[0];
                $driver = $params['driver'] ?? null;
            }

            if(!$driver) {
                throw new \RuntimeException('Driver not found for connection '.$connectionName);
            }

            // Register the appropriate adapter based on the driver
            $this->registerAdapterForDriver($container, $driver, $connectionName, $connectionServiceId);
        }
    }

    private function registerAdapterForDriver(
        ContainerBuilder $container,
        string $driver,
        string $connectionName,
        string $connectionServiceId
    ): void {
        match ($driver) {
            'pdo_mysql' => $this->registerMySQLAdapter($container, $connectionName, $connectionServiceId),
            'pdo_pgsql' => $this->registerPostgreSQLAdapter($container, $connectionName, $connectionServiceId),
            'pdo_sqlite' => $this->registerSQLiteAdapter($container, $connectionName, $connectionServiceId),
            'pdo_sqlsrv', 'sqlsrv' => $this->registerSqlServerAdapter($container, $connectionName, $connectionServiceId),
            default => null,
        };
    }

    private function registerMySQLAdapter(ContainerBuilder $container, string $connectionName, string $connectionServiceId): void
    {
        $serviceId = 'pro_backup.database.mysql.'.$connectionName;

        if ($container->has($serviceId)) {
            return;
        }

        $mysqlDef = $container->register($serviceId, MySQLAdapter::class);
        $mysqlDef->setArguments([
            new Reference($connectionServiceId),
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $mysqlDef->addTag('pro_backup.database_adapter');
    }

    private function registerPostgreSQLAdapter(ContainerBuilder $container, string $connectionName, string $connectionServiceId): void
    {
        $serviceId = 'pro_backup.database.postgresql.'.$connectionName;

        if ($container->has($serviceId)) {
            return;
        }

        $pgDef = $container->register($serviceId, PostgreSQLAdapter::class);
        $pgDef->setArguments([
            new Reference($connectionServiceId),
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
            new Reference('pro_backup.process.factory'),
        ]);
        $pgDef->addTag('pro_backup.database_adapter');
    }

    private function registerSQLiteAdapter(ContainerBuilder $container, string $connectionName, string $connectionServiceId): void
    {
        $serviceId = 'pro_backup.database.sqlite.'.$connectionName;

        if ($container->has($serviceId)) {
            return;
        }

        $sqliteDef = $container->register($serviceId, SQLiteAdapter::class);
        $sqliteDef->setArguments([
            new Reference($connectionServiceId),
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $sqliteDef->addTag('pro_backup.database_adapter');
    }

    private function registerSqlServerAdapter(ContainerBuilder $container, string $connectionName, string $connectionServiceId): void
    {
        $serviceId = 'pro_backup.database.sqlserver.'.$connectionName;

        if ($container->has($serviceId)) {
            return;
        }

        $sqlServerDef = $container->register($serviceId, SqlServerAdapter::class);
        $sqlServerDef->setArguments([
            new Reference($connectionServiceId),
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $sqlServerDef->addTag('pro_backup.database_adapter');
    }
}
