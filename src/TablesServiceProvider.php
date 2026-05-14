<?php

namespace Mercurio\Tables;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Mercurio\Tables\Console\SyncSavedViewsCommand;
use Mercurio\Tables\Export\ExportWriterRegistry;
use Mercurio\Tables\Filter\FilterPipeline;
use Mercurio\Tables\Prefs\UserPrefsResolver;
use Mercurio\Tables\Routing\PendingTablesResource;
use Mercurio\Tables\Services\SystemViewSyncer;
use Mercurio\Tables\Summary\SummaryCardRegistry;
use Mercurio\Tables\Table\TableBuilder;

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
        $this->app->singleton(FilterPipeline::class);
        $this->app->singleton(TableBuilder::class);

        $this->app->singleton(ExportWriterRegistry::class, function () {
            $registry = new ExportWriterRegistry;
            foreach ((array) config('tables.export.writers', []) as $format => $class) {
                if (is_string($format) && is_string($class) && $class !== '') {
                    $registry->register($format, $class);
                }
            }

            return $registry;
        });

        $this->app->singleton(SummaryCardRegistry::class, function () {
            $registry = new SummaryCardRegistry;
            foreach ((array) config('tables.summary_cards', []) as $key => $class) {
                if (is_string($key) && is_string($class) && $class !== '') {
                    $registry->register($key, $class);
                }
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tables');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'tables');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Router::macro('tablesResource', function (string $path, string $controller): PendingTablesResource {
            /** @var Router $this */
            return new PendingTablesResource($this, $path, $controller);
        });

        Router::macro('tablesPage', function (string $path, string $resourceClass): PendingTablesResource {
            /** @var Router $this */
            return PendingTablesResource::page($this, $path, $resourceClass);
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
                __DIR__.'/../resources/lang' => $this->langPublishPath(),
            ], 'tables-lang');

            $this->publishes([
                __DIR__.'/../resources/js' => resource_path('js/vendor/tables'),
                __DIR__.'/../resources/scss' => resource_path('scss/vendor/tables'),
            ], 'tables-assets');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'tables-migrations');
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

    private function langPublishPath(): string
    {
        if (function_exists('lang_path')) {
            return lang_path('vendor/tables');
        }

        return resource_path('lang/vendor/tables');
    }
}
