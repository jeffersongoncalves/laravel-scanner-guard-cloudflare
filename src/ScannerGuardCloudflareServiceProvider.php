<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardCloudflare;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ScannerGuardCloudflareServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-scanner-guard-cloudflare')
            ->hasConfigFile('scanner-guard-cloudflare');
    }
}
