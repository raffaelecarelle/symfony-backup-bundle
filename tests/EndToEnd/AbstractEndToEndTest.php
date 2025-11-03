<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Manager\BackupManager;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as SymfonyApplication;
use Symfony\Component\Filesystem\Filesystem;
use TestApp\Kernel;

/**
 * Base class for end-to-end tests.
 *
 * This class boots the minimal Symfony TestApp and uses the real
 * services wired by the bundle to exercise end-to-end behavior.
 */
abstract class AbstractEndToEndTest extends TestCase
{
    protected BackupManager $backupManager;
    protected string $tempDir;
    protected Filesystem $filesystem;

    protected Kernel $kernel;

    protected ContainerInterface $container;

    protected SymfonyApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        // Per-test temp dir for auxiliary files (not the backup_dir, which is configured in the TestApp)
        $this->tempDir = sys_get_temp_dir().'/probackup_e2e_tests_'.uniqid('', true);
        $this->filesystem->mkdir($this->tempDir);

        // Boot the TestApp kernel
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer();

        // Fetch the real BackupManager service from the container
        /** @var BackupManager $manager */
        $manager = $this->container->get('pro_backup.manager');
        $this->backupManager = $manager;

        // Create a Symfony Console Application bound to the kernel so
        // tagged commands are auto-registered.
        $this->application = new SymfonyApplication($this->kernel);

        // Let child classes do additional setup (e.g., seed DB)
        $this->setupTest();
    }

    /**
     * Additional setup to be implemented by child classes.
     */
    protected function setupTest(): void
    {
        // Optional in child classes
    }

    protected function tearDown(): void
    {
        // Shutdown kernel
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }

        // Clean up the temporary directory
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Creates a temporary file with the given content (under per-test temp dir).
     */
    protected function createTempFile(string $filename, string $content): string
    {
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Helper: create a temporary SQLite database with the provided schema.
     *
     * @param string                             $filename Target filename under the per-test temp dir
     * @param array<string,array<string,string>> $schema   Table => column => type
     *
     * @return string Absolute path to the created SQLite database file
     */
    protected function createTempSQLiteDatabase(string $filename, array $schema): string
    {
        $dbPath = $this->tempDir.'/'.$filename;
        $dir = \dirname($dbPath);
        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir, 0777);
        }

        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach ($schema as $table => $columns) {
            $colsSql = [];
            foreach ($columns as $name => $type) {
                $colsSql[] = \sprintf('%s %s', $name, $type);
            }
            $sql = \sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $table, implode(', ', $colsSql));
            $pdo->exec($sql);
        }

        return $dbPath;
    }
}
