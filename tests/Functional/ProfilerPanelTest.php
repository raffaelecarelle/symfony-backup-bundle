<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfilerPanelTest extends WebTestCase
{
    private function assertToolbarContainsBackupPanel(KernelBrowser $client): void
    {
        $profile = $client->getProfile();
        self::assertNotNull($profile, 'Symfony Profiler should be enabled for this request.');
        self::assertTrue($profile->hasCollector('backup'), 'The "backup" data collector should be registered.');

        // Fetch the toolbar HTML for this request token and look for our panel markup
        $token = $profile->getToken();
        $client->request('GET', \sprintf('/_wdt/%s', $token));
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('backup-count-toolbar', $content, 'Toolbar should contain PRO Backup panel markup.');
        self::assertStringContainsString('Backup', $content, 'Toolbar should show the Backup label.');
    }

    public function testBackupProfilerPanelIsVisibleWhenEnabled(): void
    {
        $client = self::createClient(options: [
            'environment' => 'test',
            'debug' => true,
        ]);
        $client->enableProfiler();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->assertToolbarContainsBackupPanel($client);
    }

    public function testBackupProfilerPanelIsHiddenWhenDisabledInConfig(): void
    {
        $client = self::createClient(options: [
            'environment' => 'no_prof', // custom env with profiler bundle enabled but pro_backup.profiler disabled
            'debug' => true,
        ]);
        $client->enableProfiler();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile, 'Symfony Profiler should be enabled for this request.');
        self::assertFalse($profile->hasCollector('backup'), 'The "backup" collector must not be registered when disabled.');

        // Toolbar should not contain our panel markup
        $token = $profile->getToken();
        $client->request('GET', \sprintf('/_wdt/%s', $token));
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('backup-count-toolbar', $content, 'Toolbar must not contain PRO Backup panel when disabled.');
    }
}
