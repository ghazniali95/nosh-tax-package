<?php

namespace Nosh\OmniTax\Tests;

use Nosh\OmniTax\Contracts\Transport;
use Nosh\OmniTax\OmniTaxServiceProvider;
use Nosh\OmniTax\Transport\MockTransport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OmniTaxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('omnitax.default', 'fbr');
        $app['config']->set('omnitax.sandbox', true);
        $app['config']->set('omnitax.transport', 'mock');
        $app['config']->set('omnitax.credentials.driver', 'env');
        $app['config']->set('omnitax.seller', [
            'ntncnic' => '0786909', 'name' => 'Karachi Grill House',
            'province' => 'Sindh', 'address' => 'Karachi',
        ]);
        $app['config']->set('omnitax.authorities.fbr.token', 'test-token');

        // Bind the mock transport so no network is touched.
        $app->singleton(Transport::class, fn () => new MockTransport());

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
