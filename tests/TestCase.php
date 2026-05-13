<?php

namespace Mercurio\Tables\Tests;

use Illuminate\Support\Facades\Gate;
use Mercurio\Tables\TablesServiceProvider;
use Mercurio\Tables\Tests\Fixtures\Models\TestPost;
use Mercurio\Tables\Tests\Fixtures\Policies\TestPostPolicy;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TablesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // SystemViewSyncer пускается в боевой среде на первом HTTP-запросе.
        // В тестах boot() уходит по runningInConsole-ветке, но безопасный default — false.
        $app['config']->set('tables.sync_system_views', false);

        Gate::policy(TestPost::class, TestPostPolicy::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
