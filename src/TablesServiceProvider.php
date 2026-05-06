<?php

namespace Mercurio\Tables;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Mercurio\Tables\Console\SyncSavedViewsCommand;
use Mercurio\Tables\Prefs\UserPrefsResolver;
use Mercurio\Tables\Routing\PendingTablesResource;
use Mercurio\Tables\Services\SystemViewSyncer;

class TablesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tables.php', 'tables');

        $this->app->singleton(ResourceRegistry::class, function ($app) {
            $registry = new ResourceRegistry;
            foreach ((array) config('tables.resources', []) as $cls) {
                if (is_string($cls) && $cls !== '') {
                    $registry->register($cls);
                }
            }

            return $registry;
        });

        $this->app->singleton(UserPrefsResolver::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tables');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components');

        Router::macro('tablesResource', function (string $path, string $controller): PendingTablesResource {
            /** @var Router $this */
            return new PendingTablesResource($this, $path, $controller);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncSavedViewsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/tables.php' => config_path('tables.php'),
            ], 'tables-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/tables'),
            ], 'tables-views');

            $this->publishes([
                __DIR__.'/../resources/js' => resource_path('js/vendor/tables'),
                __DIR__.'/../resources/scss' => resource_path('scss/vendor/tables'),
            ], 'tables-assets');
        }

        if (config('tables.sync_system_views', true) && ! $this->app->runningInConsole()) {
            try {
                $this->app->make(SystemViewSyncer::class)->sync(
                    $this->app->make(ResourceRegistry::class)
                );
            } catch (\Throwable $e) {
                Log::warning('tables.savedviews.sync_failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
