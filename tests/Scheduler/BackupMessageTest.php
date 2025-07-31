<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Scheduler;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Scheduler\BackupMessage;

class BackupMessageTest extends TestCase
{
    public function testConstructor(): void
    {
        $message = new BackupMessage('database');

        $this->assertEquals('database', $message->getType());
        $this->assertNull($message->getName());
        $this->assertNull($message->getStorage());
        $this->assertNull($message->getCompression());
        $this->assertNull($message->getOutputPath());
        $this->assertEquals([], $message->getExclusions());
    }

    public function testName(): void
    {
        $message = new BackupMessage('database');

        // Default value
        $this->assertNull($message->getName());

        // Test setter and getter
        $message->setName('daily_backup');
        $this->assertEquals('daily_backup', $message->getName());

        // Test fluent interface
        $this->assertSame($message, $message->setName('weekly_backup'));
        $this->assertEquals('weekly_backup', $message->getName());

        // Test setting to null
        $message->setName(null);
        $this->assertNull($message->getName());
    }

    public function testStorage(): void
    {
        $message = new BackupMessage('database');

        // Default value
        $this->assertNull($message->getStorage());

        // Test setter and getter
        $message->setStorage('local');
        $this->assertEquals('local', $message->getStorage());

        // Test fluent interface
        $this->assertSame($message, $message->setStorage('s3'));
        $this->assertEquals('s3', $message->getStorage());

        // Test setting to null
        $message->setStorage(null);
        $this->assertNull($message->getStorage());
    }

    public function testCompression(): void
    {
        $message = new BackupMessage('database');

        // Default value
        $this->assertNull($message->getCompression());

        // Test setter and getter
        $message->setCompression('gzip');
        $this->assertEquals('gzip', $message->getCompression());

        // Test fluent interface
        $this->assertSame($message, $message->setCompression('zip'));
        $this->assertEquals('zip', $message->getCompression());

        // Test setting to null
        $message->setCompression(null);
        $this->assertNull($message->getCompression());
    }

    public function testOutputPath(): void
    {
        $message = new BackupMessage('database');

        // Default value
        $this->assertNull($message->getOutputPath());

        // Test setter and getter
        $message->setOutputPath('/path/to/backups');
        $this->assertEquals('/path/to/backups', $message->getOutputPath());

        // Test fluent interface
        $this->assertSame($message, $message->setOutputPath('/new/path'));
        $this->assertEquals('/new/path', $message->getOutputPath());

        // Test setting to null
        $message->setOutputPath(null);
        $this->assertNull($message->getOutputPath());
    }

    public function testExclusions(): void
    {
        $message = new BackupMessage('database');

        // Default value
        $this->assertEquals([], $message->getExclusions());

        // Test setter and getter
        $exclusions = ['cache', 'logs'];
        $message->setExclusions($exclusions);
        $this->assertEquals($exclusions, $message->getExclusions());

        // Test fluent interface
        $newExclusions = ['temp'];
        $this->assertSame($message, $message->setExclusions($newExclusions));
        $this->assertEquals($newExclusions, $message->getExclusions());
    }

    public function testToConfigurationWithAllProperties(): void
    {
        $message = new BackupMessage('database');
        $message->setName('daily_backup');
        $message->setStorage('local');
        $message->setCompression('gzip');
        $message->setOutputPath('/path/to/backups');
        $message->setExclusions(['cache', 'logs']);

        $config = $message->toConfiguration();

        $this->assertInstanceOf(BackupConfiguration::class, $config);
        $this->assertEquals('database', $config->getType());
        $this->assertEquals('daily_backup', $config->getName());
        $this->assertEquals('local', $config->getStorage());
        $this->assertEquals('gzip', $config->getCompression());
        $this->assertEquals('/path/to/backups', $config->getOutputPath());
        $this->assertEquals(['cache', 'logs'], $config->getExclusions());
    }

    public function testToConfigurationWithDefaultName(): void
    {
        $message = new BackupMessage('database');
        // Don't set a name

        $config = $message->toConfiguration();

        $this->assertInstanceOf(BackupConfiguration::class, $config);
        $this->assertEquals('database', $config->getType());
        // Name should be auto-generated
        $this->assertStringStartsWith('database_', $config->getName());
        $this->assertMatchesRegularExpression('/database_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', $config->getName());
    }

    public function testToConfigurationWithMinimalProperties(): void
    {
        $message = new BackupMessage('filesystem');
        // Don't set any other properties

        $config = $message->toConfiguration();

        $this->assertInstanceOf(BackupConfiguration::class, $config);
        $this->assertEquals('filesystem', $config->getType());
        // Name should be auto-generated
        $this->assertStringStartsWith('filesystem_', $config->getName());
        // Default values from BackupConfiguration
        $this->assertEquals('local', $config->getStorage());
        $this->assertNull($config->getCompression());
        $this->assertNull($config->getOutputPath());
        $this->assertEquals([], $config->getExclusions());
    }
}
