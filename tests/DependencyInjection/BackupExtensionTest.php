<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\ProBackupExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class BackupExtensionTest extends TestCase
{
    private ProBackupExtension $backupExtension;

    private ContainerBuilder $containerBuilder;

    protected function setUp(): void
    {
        $this->backupExtension = new ProBackupExtension();
        $this->containerBuilder = new ContainerBuilder();
    }

    public function testLoadWithValidConfiguration(): void
    {
        $configs = [
            'backup_dir' => '/path/to/backups',
            'profiler' => ['enabled' => true],
            'schedule' => ['enabled' => false],
            'storage' => [
                'local' => [
                    'options' => [
                        'path' => '/path/to/local/storage',
                        'permissions' => 0755,
                    ],
                ],
            ],
            'database' => [
                'enabled' => true,
                'connections' => ['default'],
            ],
            'compression' => [
                'gzip' => ['level' => 6, 'keep_original' => false],
                'zip' => ['level' => 6, 'keep_original' => false],
            ],
            'default_storage' => 'local',
        ];

        $this->backupExtension->load([$configs], $this->containerBuilder);

        $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.manager'));
        $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.storage.local'));
        $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.compression.gzip'));
        $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.compression.zip'));
        $this->assertFalse($this->containerBuilder->hasDefinition('pro_backup.scheduler'));
    }

    public function testLoadWithEnabledScheduler(): void
    {
        $configs = [
            'backup_dir' => '/path/to/backups',
            'profiler' => ['enabled' => false],
            'schedule' => ['enabled' => true, 'database' => ['frequency' => 'daily']],
            'default_storage' => 'local',
            'storage' => [
                'local' => [
                    'options' => [
                        'path' => '/path/to/local/storage',
                    ],
                ],
            ],
        ];

        $this->backupExtension->load([$configs], $this->containerBuilder);

        $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.scheduler'));
    }

    public function testLoadThrowsExceptionForMissingAWSSDK(): void
    {
        $configs = [
            'backup_dir' => '/path/to/backups',
            'default_storage' => 'local',
            'storage' => [
                's3' => [
                    'enabled' => true,
                    'options' => [
                        'region' => 'us-east-1',
                        'credentials' => [
                            'key' => 'your-key',
                            'secret' => 'your-secret',
                        ],
                        'bucket' => 'my-bucket',
                        'prefix' => 'backups/',
                    ],
                ],
            ],
        ];

        if (class_exists(\Aws\S3\S3Client::class)) {
            $this->backupExtension->load([$configs], $this->containerBuilder);
            $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.aws_s3_client'));
            $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.storage.s3'));

            return;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AWS SDK is not installed. Run "composer require aws/aws-sdk-php".');
        $this->backupExtension->load([$configs], $this->containerBuilder);
    }

    public function testLoadThrowsExceptionForMissingGoogleSDK(): void
    {
        $configs = [
            'backup_dir' => '/path/to/backups',
            'default_storage' => 'local',
            'storage' => [
                'google_cloud' => [
                    'enabled' => true,
                    'options' => [
                        'project_id' => 'my-project',
                        'key_file' => '/path/to/key.json',
                        'bucket' => 'my-bucket',
                        'prefix' => 'backups/',
                    ],
                ],
            ],
        ];

        if (class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            $this->backupExtension->load([$configs], $this->containerBuilder);
            $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.google_cloud_client'));
            $this->assertTrue($this->containerBuilder->hasDefinition('pro_backup.storage.google_cloud'));

            return;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Cloud SDK is not installed. Run "composer require google/cloud-storage".');
        $this->backupExtension->load([$configs], $this->containerBuilder);
    }

    public function testLoadWithProfilerEnabled(): void
    {
        $configs = [
            'backup_dir' => '/path/to/backups',
            'profiler' => ['enabled' => true],
            'schedule' => ['enabled' => false],
            'default_storage' => 'local',
            'storage' => [
                'local' => [
                    'options' => [
                        'path' => '/path/to/local/storage',
                    ],
                ],
            ],
        ];

        $this->backupExtension->load([$configs], $this->containerBuilder);

        $this->assertArrayHasKey('pro_backup.config', $this->containerBuilder->getParameterBag()->all());
    }
}
