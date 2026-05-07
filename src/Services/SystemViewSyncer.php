<?php

namespace Mercurio\Tables\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\SavedView as SavedViewModel;
use Mercurio\Tables\ResourceRegistry;
use Mercurio\Tables\View\SavedView;

final class SystemViewSyncer
{
    public function __construct(private CacheRepository $cache) {}

    public function sync(ResourceRegistry $registry): void
    {
        try {
            if (! Schema::hasTable((new SavedViewModel)->getTable())) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('tables.savedviews.sync_skipped', ['reason' => $e->getMessage()]);

            return;
        }

        foreach ($registry->all() as $resource) {
            $this->syncResource($resource);
        }
    }

    private function syncResource(ListResource $resource): void
    {
        /** @var array<int, SavedView> $views */
        $views = $resource->savedViews();
        if ($views === []) {
            return;
        }

        $resourceKey = $resource->key();
        $fp = SavedView::logSyncFingerprint($resourceKey, $views);
        $cacheKey = "tables.sysviews.fp:{$resourceKey}";

        if ((string) $this->cache->get($cacheKey) === $fp) {
            return;
        }

        $declaredKeys = [];
        foreach ($views as $idx => $view) {
            $declaredKeys[] = $view->key;
            $position = $view->getPosition() ?? $idx;

            SavedViewModel::query()
                ->where('resource_key', $resourceKey)
                ->where('key', $view->key)
                ->whereNull('user_id')
                ->updateOrInsert(
                    [
                        'resource_key' => $resourceKey,
                        'key' => $view->key,
                        'user_id' => null,
                    ],
                    [
                        'name' => $view->label,
                        'color' => $view->getColor(),
                        'icon' => $view->getIcon(),
                        'position' => $position,
                        'is_default' => $view->isDefault(),
                        'is_system' => true,
                        'state_json' => json_encode(['view' => $view->key], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
        }

        SavedViewModel::query()
            ->where('resource_key', $resourceKey)
            ->where('is_system', true)
            ->whereNull('user_id')
            ->whereNotIn('key', $declaredKeys)
            ->delete();

        $this->cache->forever($cacheKey, $fp);

        Log::info('tables.savedviews.sync', [
            'resource' => $resourceKey,
            'views' => count($views),
            'fingerprint' => $fp,
        ]);
    }
}
