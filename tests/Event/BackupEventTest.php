<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Event;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Event\BackupEvent;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Model\BackupResult;
use Symfony\Contracts\EventDispatcher\Event;

class BackupEventTest extends TestCase
{
    private BackupConfiguration $configuration;

    private BackupResult $result;

    protected function setUp(): void
    {
        $this->configuration = new BackupConfiguration();
        $this->configuration->setType('database');
        $this->configuration->setName('test_backup');

        $this->result = new BackupResult(true, '/path/to/backup.sql.gz', 1024);
    }

    public function testConstructorWithConfigurationOnly(): void
    {
        $event = new BackupEvent($this->configuration);

        $this->assertSame($this->configuration, $event->getConfiguration());
        $this->assertNull($event->getResult());
    }

    public function testConstructorWithConfigurationAndResult(): void
    {
        $event = new BackupEvent($this->configuration, $this->result);

        $this->assertSame($this->configuration, $event->getConfiguration());
        $this->assertSame($this->result, $event->getResult());
    }

    public function testEventInheritance(): void
    {
        $event = new BackupEvent($this->configuration);

        $this->assertInstanceOf(Event::class, $event);
    }
}
