<?php

namespace Nosh\OmniTax;

use Illuminate\Support\ServiceProvider;
use Nosh\OmniTax\Console\RetryFailedCommand;
use Nosh\OmniTax\Console\StatusCommand;
use Nosh\OmniTax\Console\SubmitPendingCommand;
use Nosh\OmniTax\Console\SyncCommand;
use Nosh\OmniTax\Console\TokenCheckCommand;

class OmniTaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/omnitax.php', 'omnitax');

        $this->app->singleton('omnitax', function ($app) {
            return new FiscalManager($app, $app['config']->get('omnitax'));
        });

        $this->app->alias('omnitax', FiscalManager::class);
    }

    public function boot(): void
    {
        // Config
        $this->publishes([
            __DIR__.'/../config/omnitax.php' => $this->configPath('omnitax.php'),
        ], 'omnitax-config');

        // Migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => $this->databasePath('migrations'),
        ], 'omnitax-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Authority logos (for receipts)
        $this->publishes([
            __DIR__.'/../resources/logos' => $this->resourcePath('vendor/omnitax/logos'),
        ], 'omnitax-logos');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCommand::class,
                SubmitPendingCommand::class,
                RetryFailedCommand::class,
                StatusCommand::class,
                TokenCheckCommand::class,
            ]);
        }
    }

    // Path helpers that also work under Testbench / bare containers.

    protected function configPath(string $path): string
    {
        return function_exists('config_path') ? config_path($path) : base_path('config/'.$path);
    }

    protected function databasePath(string $path): string
    {
        return function_exists('database_path') ? database_path($path) : base_path('database/'.$path);
    }

    protected function resourcePath(string $path): string
    {
        return function_exists('resource_path') ? resource_path($path) : base_path('resources/'.$path);
    }
}
