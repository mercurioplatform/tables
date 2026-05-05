<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Mercurio\Tables\Models\SavedView as SavedViewModel;

final class UserSavedViewLoader
{
    /** @var array<string, Collection<int, SavedViewModel>> */
    private array $cache = [];

    public function loadFor(string $resourceKey): Collection
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            return collect();
        }

        $cacheKey = $resourceKey.':'.$userId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $items = SavedViewModel::query()
            ->forResource($resourceKey)
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
