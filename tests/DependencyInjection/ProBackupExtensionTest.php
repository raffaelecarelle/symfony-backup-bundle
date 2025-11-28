<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\ProBackupExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ProBackupExtensionTest extends TestCase
{
    private function getMinimalConfig(): array
    {
        return [
            'pro_backup' => [
                'backup_dir' => sys_get_temp_dir() . '/backups',
                'default_storage' => 'local',
                'storage' => [
                    'local' => [
                        'adapter' => 'local',
                        'options' => [
                            'path' => sys_get_temp_dir() . '/backups',
                            'permissions' => 0755,
                        ],
                    ],
                ],
                'database' => [
                    'enabled' => true,
                    'compression' => 'gzip',
                    'retention_days' => 30,
                ],
                'filesystem' => [
                    'enabled' => false,
                    'compression' => 'zip',
                    'retention_days' => 7,
                ],
                'schedule' => [
                    'enabled' => false,
                    'database' => ['enabled' => true],
                    'filesystem' => ['enabled' => false],
                ],
                'compression' => [
                    'gzip' => ['level' => 6, 'keep_original' => false],
                    'zip' => ['level' => 6, 'keep_original' => false],
                ],
                'profiler' => ['enabled' => false],
            ],
        ];
    }

    public function testManagerGetsDefaultStorageAndConfigMethodCalls(): void
    {
        $container = new ContainerBuilder();
        $ext = new ProBackupExtension();
        $config = $this->getMinimalConfig();

        $ext->load($config, $container);

        $def = $container->getDefinition('pro_backup.manager');
        $methodCalls = $def->getMethodCalls();
        $methods = array_map(static fn (array $call) => $call[0], $methodCalls);

        self::assertContains('setDefaultStorage', $methods, 'Manager should receive setDefaultStorage method call');
        self::assertContains('setConfig', $methods, 'Manager should receive setConfig method call');
    }

    public function testSchedulerArgumentIndexFixed(): void
    {
        $container = new ContainerBuilder();
        $ext = new ProBackupExtension();
        $config = $this->getMinimalConfig();
        $config['pro_backup']['schedule']['enabled'] = true;
        $config['pro_backup']['schedule']['database']['enabled'] = true;
        $config['pro_backup']['schedule']['filesystem']['enabled'] = false;

        $ext->load($config, $container);

        $def = $container->getDefinition('pro_backup.scheduler');
        $arg0 = $def->getArgument(0);

        self::assertIsArray($arg0);
        self::assertArrayHasKey('database', $arg0);
        self::assertArrayHasKey('filesystem', $arg0);
    }
}
