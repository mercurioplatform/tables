<?php

namespace Mercurio\Tables\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\ResourceRegistry;
use Mercurio\Tables\Services\SystemViewSyncer;

class SyncSavedViewsCommand extends Command
{
    protected $signature = 'tables:sync-views {--force : Forget cache markers and re-sync}';

    protected $description = 'Sync system saved views into the saved-views table';

    public function handle(SystemViewSyncer $syncer, ResourceRegistry $registry): int
    {
        $force = (bool) $this->option('force');

        Log::info('tables.savedviews.sync.command_run', ['force' => $force]);

        if ($force) {
            foreach ($registry->classes() as $class) {
                $resource = app($class);
                Cache::forget('tables.sysviews.fp:'.$resource->key());
            }
        }

        $syncer->sync($registry);

        $resourceCount = count($registry->classes());
        $this->info("Synced views across {$resourceCount} resource(s).");

        return self::SUCCESS;
    }
}
