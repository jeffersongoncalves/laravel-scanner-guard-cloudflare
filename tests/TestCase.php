<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardCloudflare\Tests;

use JeffersonGoncalves\ScannerGuard\ScannerGuardServiceProvider;
use JeffersonGoncalves\ScannerGuardCloudflare\ScannerGuardCloudflareServiceProvider;
use JeffersonGoncalves\VisitorFingerprint\VisitorFingerprintServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            VisitorFingerprintServiceProvider::class,
            ScannerGuardServiceProvider::class,
            ScannerGuardCloudflareServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('scanner-guard-cloudflare.api_token', 'test-token');
        $app['config']->set('scanner-guard-cloudflare.account_id', 'acc123');
        $app['config']->set('scanner-guard-cloudflare.list_id', 'list456');
    }
}
