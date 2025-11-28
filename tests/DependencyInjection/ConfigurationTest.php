<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    public function testCronExpressionAcceptedAndPreserved(): void
    {
        $config = [
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
                ],
                'filesystem' => [
                    'enabled' => false,
                ],
                'schedule' => [
                    'enabled' => true,
                    'database' => [
                        'enabled' => true,
                        'cron_expression' => '5 4 * * *',
                    ],
                    'filesystem' => [
                        'enabled' => true,
                        'cron_expression' => '15 3 * * 1',
                    ],
                ],
            ],
        ];

        $processor = new Processor();
        $processed = $processor->processConfiguration(new Configuration(), $config);

        self::assertSame('5 4 * * *', $processed['schedule']['database']['cron_expression']);
        self::assertSame('15 3 * * 1', $processed['schedule']['filesystem']['cron_expression']);
        // Ensure defaults preserved
        self::assertSame('daily', $processed['schedule']['database']['frequency']);
        self::assertSame('weekly', $processed['schedule']['filesystem']['frequency']);
    }
}
