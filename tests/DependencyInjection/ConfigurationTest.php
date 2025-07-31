<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    private $processor;
    private $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    public function testDefaultConfig(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [[]]);

        // Test default values
        $this->assertEquals('%kernel.project_dir%/var/backups', $config['backup_dir']);
        $this->assertEquals('local', $config['default_storage']);

        // Test storage defaults
        $this->assertEquals('local', $config['storage']['local']['adapter']);
        $this->assertEquals('%kernel.project_dir%/var/backups', $config['storage']['local']['options']['path']);
        $this->assertEquals(0755, $config['storage']['local']['options']['permissions']);

        // Test database defaults
        $this->assertTrue($config['database']['enabled']);
        $this->assertEquals(['default'], $config['database']['connections']);
        $this->assertEquals('gzip', $config['database']['compression']);
        $this->assertEquals(30, $config['database']['retention_days']);
        $this->assertEquals(['cache_items', 'sessions'], $config['database']['exclude_tables']);

        // Test database options defaults
        $this->assertTrue($config['database']['options']['mysql']['single_transaction']);
        $this->assertTrue($config['database']['options']['mysql']['routines']);
        $this->assertEquals('plain', $config['database']['options']['postgresql']['format']);
        $this->assertTrue($config['database']['options']['sqlserver']['single_user']);
        $this->assertTrue($config['database']['options']['sqlite']['backup_existing']);

        // Test filesystem defaults
        $this->assertFalse($config['filesystem']['enabled']);
        $this->assertEquals('zip', $config['filesystem']['compression']);
        $this->assertEquals(7, $config['filesystem']['retention_days']);

        // Test compression defaults
        $this->assertEquals(6, $config['compression']['gzip']['level']);
        $this->assertFalse($config['compression']['gzip']['keep_original']);
        $this->assertEquals(6, $config['compression']['zip']['level']);
        $this->assertFalse($config['compression']['zip']['keep_original']);

        // Test schedule defaults
        $this->assertFalse($config['schedule']['enabled']);
        $this->assertEquals('daily', $config['schedule']['database']['frequency']);
        $this->assertEquals('02:00', $config['schedule']['database']['time']);
        $this->assertTrue($config['schedule']['database']['enabled']);
        $this->assertEquals('weekly', $config['schedule']['filesystem']['frequency']);
        $this->assertEquals('03:00', $config['schedule']['filesystem']['time']);
        $this->assertFalse($config['schedule']['filesystem']['enabled']);

        // Test notifications defaults
        $this->assertFalse($config['notifications']['on_success']);
        $this->assertTrue($config['notifications']['on_failure']);
        $this->assertEquals(['email'], $config['notifications']['channels']);
    }

    public function testCustomConfig(): void
    {
        $customConfig = [
            'backup_dir' => '/custom/backup/dir',
            'default_storage' => 's3',
            'storage' => [
                'local' => [
                    'options' => [
                        'path' => '/custom/local/path',
                        'permissions' => 0700,
                    ],
                ],
                's3' => [
                    'enabled' => true,
                    'options' => [
                        'bucket' => 'my-backup-bucket',
                        'region' => 'eu-west-1',
                        'prefix' => 'my-backups',
                        'credentials' => [
                            'key' => 'my-access-key',
                            'secret' => 'my-secret-key',
                        ],
                    ],
                ],
            ],
            'database' => [
                'enabled' => true,
                'connections' => ['default', 'custom'],
                'compression' => 'zip',
                'retention_days' => 60,
                'exclude_tables' => ['logs', 'temp_data'],
                'options' => [
                    'mysql' => [
                        'single_transaction' => false,
                        'routines' => false,
                    ],
                    'postgresql' => [
                        'format' => 'custom',
                        'verbose' => false,
                    ],
                ],
            ],
            'filesystem' => [
                'enabled' => true,
                'paths' => [
                    [
                        'path' => '/var/www/html',
                        'exclude' => ['cache', 'logs'],
                    ],
                    [
                        'path' => '/etc/nginx',
                        'exclude' => [],
                    ],
                ],
                'compression' => 'gzip',
                'retention_days' => 14,
            ],
            'compression' => [
                'gzip' => [
                    'level' => 9,
                    'keep_original' => true,
                ],
                'zip' => [
                    'level' => 3,
                    'keep_original' => true,
                ],
            ],
            'schedule' => [
                'enabled' => true,
                'database' => [
                    'frequency' => 'weekly',
                    'time' => '01:00',
                ],
                'filesystem' => [
                    'frequency' => 'monthly',
                    'time' => '04:00',
                    'enabled' => true,
                ],
            ],
            'notifications' => [
                'on_success' => true,
                'on_failure' => true,
                'channels' => ['email', 'slack'],
            ],
            'profiler' => [
                'enabled' => true,
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$customConfig]);

        // Test custom values
        $this->assertEquals('/custom/backup/dir', $config['backup_dir']);
        $this->assertEquals('s3', $config['default_storage']);

        // Test storage custom values
        $this->assertEquals('/custom/local/path', $config['storage']['local']['options']['path']);
        $this->assertEquals(0700, $config['storage']['local']['options']['permissions']);
        $this->assertTrue($config['storage']['s3']['enabled']);
        $this->assertEquals('my-backup-bucket', $config['storage']['s3']['options']['bucket']);
        $this->assertEquals('eu-west-1', $config['storage']['s3']['options']['region']);
        $this->assertEquals('my-backups', $config['storage']['s3']['options']['prefix']);
        $this->assertEquals('my-access-key', $config['storage']['s3']['options']['credentials']['key']);
        $this->assertEquals('my-secret-key', $config['storage']['s3']['options']['credentials']['secret']);

        // Test database custom values
        $this->assertTrue($config['database']['enabled']);
        $this->assertEquals(['default', 'custom'], $config['database']['connections']);
        $this->assertEquals('zip', $config['database']['compression']);
        $this->assertEquals(60, $config['database']['retention_days']);
        $this->assertEquals(['logs', 'temp_data'], $config['database']['exclude_tables']);

        // Test database options custom values
        $this->assertFalse($config['database']['options']['mysql']['single_transaction']);
        $this->assertFalse($config['database']['options']['mysql']['routines']);
        $this->assertEquals('custom', $config['database']['options']['postgresql']['format']);
        $this->assertFalse($config['database']['options']['postgresql']['verbose']);

        // Test filesystem custom values
        $this->assertTrue($config['filesystem']['enabled']);
        $this->assertCount(2, $config['filesystem']['paths']);
        $this->assertEquals('/var/www/html', $config['filesystem']['paths'][0]['path']);
        $this->assertEquals(['cache', 'logs'], $config['filesystem']['paths'][0]['exclude']);
        $this->assertEquals('/etc/nginx', $config['filesystem']['paths'][1]['path']);
        $this->assertEquals('gzip', $config['filesystem']['compression']);
        $this->assertEquals(14, $config['filesystem']['retention_days']);

        // Test compression custom values
        $this->assertEquals(9, $config['compression']['gzip']['level']);
        $this->assertTrue($config['compression']['gzip']['keep_original']);
        $this->assertEquals(3, $config['compression']['zip']['level']);
        $this->assertTrue($config['compression']['zip']['keep_original']);

        // Test schedule custom values
        $this->assertTrue($config['schedule']['enabled']);
        $this->assertEquals('weekly', $config['schedule']['database']['frequency']);
        $this->assertEquals('01:00', $config['schedule']['database']['time']);
        $this->assertEquals('monthly', $config['schedule']['filesystem']['frequency']);
        $this->assertEquals('04:00', $config['schedule']['filesystem']['time']);
        $this->assertTrue($config['schedule']['filesystem']['enabled']);

        // Test notifications custom values
        $this->assertTrue($config['notifications']['on_success']);
        $this->assertTrue($config['notifications']['on_failure']);
        $this->assertEquals(['email', 'slack'], $config['notifications']['channels']);

        // Test profiler custom values
        $this->assertTrue($config['profiler']['enabled']);
    }

    public function testInvalidCompressionLevel(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $invalidConfig = [
            'compression' => [
                'gzip' => [
                    'level' => 10, // Invalid: max is 9
                ],
            ],
        ];

        $this->processor->processConfiguration($this->configuration, [$invalidConfig]);
    }

    public function testInvalidPostgresFormat(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $invalidConfig = [
            'database' => [
                'options' => [
                    'postgresql' => [
                        'format' => 'invalid', // Invalid: must be 'plain' or 'custom'
                    ],
                ],
            ],
        ];

        $this->processor->processConfiguration($this->configuration, [$invalidConfig]);
    }

    public function testInvalidScheduleFrequency(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $invalidConfig = [
            'schedule' => [
                'database' => [
                    'frequency' => 'hourly', // Invalid: must be 'daily', 'weekly', or 'monthly'
                ],
            ],
        ];

        $this->processor->processConfiguration($this->configuration, [$invalidConfig]);
    }

    public function testS3ConfigWithoutRequiredOptions(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $invalidConfig = [
            'storage' => [
                's3' => [
                    'enabled' => true,
                    // Missing required options
                ],
            ],
        ];

        $this->processor->processConfiguration($this->configuration, [$invalidConfig]);
    }

    public function testFilesystemPathWithoutRequiredPath(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $invalidConfig = [
            'filesystem' => [
                'paths' => [
                    [
                        // Missing required 'path'
                        'exclude' => ['cache'],
                    ],
                ],
            ],
        ];

        $this->processor->processConfiguration($this->configuration, [$invalidConfig]);
    }
}
