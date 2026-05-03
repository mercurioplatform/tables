<?php

namespace Mercurio\Tables;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class TablesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tables.php', 'tables');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tables');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components');

        if ($this->app->runningInConsole()) {
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
    }
}
