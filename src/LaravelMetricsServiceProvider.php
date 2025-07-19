<?php

namespace MoonlyDays\LaravelMetrics;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelMetricsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-metrics')
            ->hasMigration('create_statistic_events_table');
    }
}
