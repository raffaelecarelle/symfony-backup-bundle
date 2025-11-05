<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use ProBackupBundle\Adapter\BackupAdapterInterface;
use ProBackupBundle\Process\Factory\ProcessFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating database adapters based on the database platform.
 */
class DatabaseAdapterFactory
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ProcessFactory $processFactory = null,
    ) {
    }

    /**
     * Create the appropriate database adapter based on the connection's platform.
     *
     * @throws \RuntimeException if the database platform is not supported
     */
    public function createAdapter(Connection $connection): BackupAdapterInterface
    {
        $platform = $connection->getDatabasePlatform();
        $logger = $this->logger ?? new NullLogger();

        return match (true) {
            class_exists(AbstractMySQLPlatform::class) && $platform instanceof AbstractMySQLPlatform => new MySQLAdapter($connection, $logger),
            class_exists(PostgreSQLPlatform::class) && $platform instanceof PostgreSQLPlatform => new PostgreSQLAdapter($connection, $logger, $this->processFactory),
            class_exists(SqlitePlatform::class) && $platform instanceof SqlitePlatform => new SQLiteAdapter($connection, $logger),
            default => throw new \RuntimeException(\sprintf('Unsupported database platform: %s', $platform::class)),
        };
    }
}
