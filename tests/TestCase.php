<?php

namespace Mercurio\Tables\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Mercurio\Tables\TablesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $packageMigrations = __DIR__.'/../database/migrations';
        if (is_dir($packageMigrations)) {
            $this->loadMigrationsFrom($packageMigrations);
        }

        $fixturesMigrations = __DIR__.'/Fixtures/JsonApi/migrations';
        if (is_dir($fixturesMigrations)) {
            $this->loadMigrationsFrom($fixturesMigrations);
        }
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TablesServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('session.driver', 'array');
        $app['config']->set('tables.guard', 'web');
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);
    }
}
