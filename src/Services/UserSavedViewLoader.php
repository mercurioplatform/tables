<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\SavedView as SavedViewModel;

final class UserSavedViewLoader
{
    /** @var array<string, Collection<int, SavedViewModel>> */
    private array $cache = [];

    public function loadFor(ListResource $resource): Collection
    {
        $guard = $resource->effectiveGuard();
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            return collect();
        }

        $cacheKey = $resource->key().':'.$userId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $items = SavedViewModel::query()
            ->forResource($resource->key())
            ->forUser((int) $userId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->cache[$cacheKey] = $items;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
