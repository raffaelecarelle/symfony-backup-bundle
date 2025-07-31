<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Compression\GzipCompression;
use ProBackupBundle\Adapter\Compression\ZipCompression;
use ProBackupBundle\Adapter\Database\MySQLAdapter;
use ProBackupBundle\Adapter\Database\PostgreSQLAdapter;
use ProBackupBundle\Adapter\Database\SQLiteAdapter;
use ProBackupBundle\Adapter\Filesystem\FilesystemAdapter;
use ProBackupBundle\Adapter\Storage\LocalAdapter;
use ProBackupBundle\Manager\BackupManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Base class for end-to-end tests.
 *
 * This class sets up a real BackupManager with actual adapters
 * instead of mocks to test the full backup process.
 */
abstract class AbstractEndToEndTest extends TestCase
{
    protected BackupManager $backupManager;
    protected string $tempDir;
    protected Filesystem $filesystem;
    protected LoggerInterface $logger;
    protected EventDispatcher $eventDispatcher;
    protected Connection $mockMySQLConnection;
    protected Connection $mockPostgreSQLConnection;
    protected Connection $mockSQLiteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for backups
        $this->tempDir = sys_get_temp_dir().'/probackup_e2e_tests_'.uniqid('', true);
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->tempDir);

        // Create a logger and event dispatcher
        $this->logger = new NullLogger();
        $this->eventDispatcher = new EventDispatcher();

        // Create mock database connections
        $this->mockMySQLConnection = $this->createMock(Connection::class);
        $this->mockMySQLConnection->method('getParams')->willReturn([
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'test_user',
            'password' => 'test_password',
            'driver' => 'pdo_mysql',
        ]);
        $this->mockMySQLConnection->method('getDatabase')->willReturn('test_db');
        $this->mockMySQLConnection->method('isConnected')->willReturn(true);

        $this->mockPostgreSQLConnection = $this->createMock(Connection::class);
        $this->mockPostgreSQLConnection->method('getParams')->willReturn([
            'host' => 'localhost',
            'port' => 5432,
            'user' => 'test_user',
            'password' => 'test_password',
            'driver' => 'pdo_pgsql',
        ]);
        $this->mockPostgreSQLConnection->method('getDatabase')->willReturn('test_db');
        $this->mockPostgreSQLConnection->method('isConnected')->willReturn(true);

        $this->mockSQLiteConnection = $this->createMock(Connection::class);
        $this->mockSQLiteConnection->method('getParams')->willReturn([
            'path' => $this->tempDir.'/test.sqlite',
            'driver' => 'pdo_sqlite',
        ]);
        $this->mockSQLiteConnection->method('getDatabase')->willReturn('main');
        $this->mockSQLiteConnection->method('isConnected')->willReturn(true);

        // Create a real BackupManager with actual adapters
        $this->backupManager = new BackupManager(
            $this->tempDir,
            $this->eventDispatcher,
            $this->logger
        );

        // Add database adapters
        $this->backupManager->addAdapter(new MySQLAdapter($this->mockMySQLConnection, $this->logger));
        $this->backupManager->addAdapter(new PostgreSQLAdapter($this->mockPostgreSQLConnection, $this->logger, null));
        $this->backupManager->addAdapter(new SQLiteAdapter($this->mockSQLiteConnection, $this->logger));

        // Add filesystem adapter
        $this->backupManager->addAdapter(new FilesystemAdapter());

        // Add compression adapters
        $this->backupManager->addCompressionAdapter('gzip', new GzipCompression());
        $this->backupManager->addCompressionAdapter('zip', new ZipCompression());

        // Add storage adapters
        $this->backupManager->addStorageAdapter('local', new LocalAdapter($this->tempDir));
        $this->backupManager->setDefaultStorage('local');

        // Additional setup can be done in child classes
        $this->setupTest();
    }

    /**
     * Additional setup to be implemented by child classes.
     */
    protected function setupTest(): void
    {
        // To be implemented by child classes if needed
    }

    protected function tearDown(): void
    {
        // Clean up the temporary directory
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Creates a temporary file with the given content.
     */
    protected function createTempFile(string $filename, string $content): string
    {
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Creates a temporary SQLite database for testing.
     */
    protected function createTempSQLiteDatabase(string $filename, array $tables = []): string
    {
        $path = $this->tempDir.'/'.$filename;
        $pdo = new \PDO('sqlite:'.$path);

        foreach ($tables as $tableName => $columns) {
            $columnsDefinition = implode(', ', array_map(fn($column, $type) => "$column $type", array_keys($columns), array_values($columns)));

            $pdo->exec("CREATE TABLE $tableName ($columnsDefinition)");
        }

        return $path;
    }
}
