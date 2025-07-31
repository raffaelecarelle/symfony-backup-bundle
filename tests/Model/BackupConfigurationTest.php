<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Model;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Model\BackupConfiguration;

class BackupConfigurationTest extends TestCase
{
    private $config;

    protected function setUp(): void
    {
        $this->config = new BackupConfiguration();
    }

    public function testType(): void
    {
        // Default value should be empty string
        $this->assertEquals('', $this->config->getType());

        // Test setter and getter
        $this->config->setType('database');
        $this->assertEquals('database', $this->config->getType());

        // Test fluent interface
        $this->assertSame($this->config, $this->config->setType('filesystem'));
        $this->assertEquals('filesystem', $this->config->getType());
    }

    public function testName(): void
    {
        // Default value should be empty string
        $this->assertEquals('', $this->config->getName());

        // Test setter and getter
        $this->config->setName('daily_backup');
        $this->assertEquals('daily_backup', $this->config->getName());

        // Test fluent interface
        $this->assertSame($this->config, $this->config->setName('weekly_backup'));
        $this->assertEquals('weekly_backup', $this->config->getName());
    }

    public function testOptions(): void
    {
        // Default value should be empty array
        $this->assertEquals([], $this->config->getOptions());

        // Test setter and getter
        $options = ['key1' => 'value1', 'key2' => 'value2'];
        $this->config->setOptions($options);
        $this->assertEquals($options, $this->config->getOptions());

        // Test fluent interface
        $newOptions = ['key3' => 'value3'];
        $this->assertSame($this->config, $this->config->setOptions($newOptions));
        $this->assertEquals($newOptions, $this->config->getOptions());
    }

    public function testOption(): void
    {
        // Default value for non-existent option should be null
        $this->assertNull($this->config->getOption('non_existent'));

        // Test with custom default value
        $this->assertEquals('default', $this->config->getOption('non_existent', 'default'));

        // Test setter and getter
        $this->config->setOption('key1', 'value1');
        $this->assertEquals('value1', $this->config->getOption('key1'));

        // Test fluent interface
        $this->assertSame($this->config, $this->config->setOption('key2', 'value2'));
        $this->assertEquals('value2', $this->config->getOption('key2'));

        // Test with array value
        $arrayValue = ['nested' => 'value'];
        $this->config->setOption('array_option', $arrayValue);
        $this->assertEquals($arrayValue, $this->config->getOption('array_option'));

        // Test that setOption adds to existing options
        $this->assertEquals([
            'key1' => 'value1',
            'key2' => 'value2',
            'array_option' => $arrayValue,
        ], $this->config->getOptions());
    }

    public function testStorage(): void
    {
        // Default value should be 'local'
        $this->assertEquals('local', $this->config->getStorage());

        // Test setter and getter
        $this->config->setStorage('s3');
        $this->assertEquals('s3', $this->config->getStorage());

        // Test fluent interface
        $this->assertSame($this->config, $this->config->setStorage('google_cloud'));
        $this->assertEquals('google_cloud', $this->config->getStorage());
    }

    public function testCompression(): void
    {
        // Default value should be null
        $this->assertNull($this->config->getCompression());

        // Test setter and getter
        $this->config->setCompression('gzip');
        $this->assertEquals('gzip', $this->config->getCompression());

        // Test fluent interface
        $this->assertSame($this->config, $this->config->setCompression('zip'));
        $this->assertEquals('zip', $this->config->getCompression());

        // Test setting to null
        $this->config->setCompression(null);
        $this->assertNull($this->config->getCompression());
    }

    public function testExclusions(): void
    {
        // Default value should be empty array
        $this->assertEquals([], $this->config->getExclusions());

        // Test setter and getter
        $exclusions = ['cache', 'logs'];
        $this->config->setExclusions($exclusions);
        $this->assertEquals($exclusions, $this->config->getExclusions());

        // Test fluent interface
        $newExclusions = ['temp'];
        $this->assertSame($this->config, $this->config->setExclusions($newExclusions));
        $this->assertEquals($newExclusions, $this->config->getExclusions());
    }

    public function testAddExclusion(): void
    {
        // Start with empty exclusions
        $this->assertEquals([], $this->config->getExclusions());

        // Add an exclusion
        $this->config->addExclusion('cache');
        $this->assertEquals(['cache'], $this->config->getExclusions());

        // Add another exclusion
        $this->config->addExclusion('logs');
        $this->assertEquals(['cache', 'logs'], $this->config->getExclusions());

        // Test fluent interface
        $this->assertSame($this->config, $this->config->addExclusion('temp'));
        $this->assertEquals(['cache', 'logs', 'temp'], $this->config->getExclusions());
    }

    public function testOutputPath(): void
    {
        // Default value should be null
        $this->assertNull($this->config->getOutputPath());

        // Test setter and getter
        $this->config->setOutputPath('/path/to/backups');
        $this->assertEquals('/path/to/backups', $this->config->getOutputPath());

        // Test fluent interface
        $this->assertSame($this->config, $this->config->setOutputPath('/new/path'));
        $this->assertEquals('/new/path', $this->config->getOutputPath());
    }
}
