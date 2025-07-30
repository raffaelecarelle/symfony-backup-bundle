<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves the appropriate database adapter type based on the Doctrine connection.
 */
class DatabaseDriverResolver
{
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     */
    public function __construct(
        private readonly Connection $connection,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Resolve the database type from the Doctrine connection.
     *
     * @return string The resolved database type (mysql, postgresql, sqlite, sqlserver)
     */
    public function resolveDriverType(): string
    {
        // Get the driver name from the connection parameters
        $params = $this->connection->getParams();
        $driverName = $params['driver'] ?? '';

        $this->logger->debug('Resolving database driver type', [
            'driver' => $driverName,
        ]);

        // Map the platform or driver to a specific adapter type
        if (stripos($driverName, 'mysql') !== false) {
            return 'mysql';
        }

        if (stripos($driverName, 'pgsql') !== false || stripos($driverName, 'postgresql') !== false) {
            return 'postgresql';
        }

        if (stripos($driverName, 'sqlite') !== false) {
            return 'sqlite';
        }

        if (stripos($driverName, 'sqlsrv') !== false || stripos($driverName, 'mssql') !== false) {
            return 'sqlserver';
        }

        // Default to 'database' if we can't determine the specific type
        $this->logger->warning('Could not determine specific database type, using generic "database" type', [
            'driver' => $driverName,
        ]);

        return 'database';
    }
}