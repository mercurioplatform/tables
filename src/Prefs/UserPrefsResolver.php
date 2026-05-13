<?php

namespace Mercurio\Tables\Prefs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\UserTablePrefs;

class UserPrefsResolver
{
    public function resolve(ListResource $resource, Request $request): UserPrefs
    {
        $fields = $resource->fieldsMemo();
        $allowedNames = array_map(fn (Field $f) => $f->name, $fields);

        $defaultColumns = array_values(array_map(
            fn (Field $f) => $f->name,
            array_filter(
                $fields,
                fn (Field $f) => ! $f->isHidden() && ! $f->isOnlyFilterable(),
            ),
        ));

        $defaults = new UserPrefs(
            columns: $defaultColumns,
            density: $resource->density(),
            perPage: $resource->perPage(),
        );

        $urlPrefs = $this->parseUrl($request, $allowedNames, $resource->key());

        $guard = $resource->effectiveGuard();
        $userId = Auth::guard($guard)->id();

        $row = null;
        $dbPrefs = new UserPrefs;

        if ($userId !== null) {
            $row = UserTablePrefs::findFor((int) $userId, $resource->key());
            if ($row !== null) {
                $raw = is_array($row->prefs_json) ? $row->prefs_json : [];
                $dbPrefs = $this->parsePrefsArray($raw, $allowedNames, $resource->key(), 'db');
            }
        }

        return $defaults->merge($dbPrefs)->merge($urlPrefs);
    }

    /**
     * @param  array<int, string>  $allowedNames
     */
    private function parseUrl(Request $request, array $allowedNames, string $resourceKey): UserPrefs
    {
        $columns = $this->parseColumns($request->query('columns'), $allowedNames, $resourceKey, 'url');

        $rawDensity = $request->query('density');
        $density = is_string($rawDensity) && in_array($rawDensity, $this->densityOptions(), true)
            ? $rawDensity
            : null;

        $rawPerPage = $request->query('per_page');
        $perPage = null;
        if ($rawPerPage !== null && $rawPerPage !== '') {
            $intVal = (int) $rawPerPage;
            if (in_array($intVal, $this->perPageOptions(), true)) {
                $perPage = $intVal;
            }
        }

        return new UserPrefs(columns: $columns, density: $density, perPage: $perPage);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $allowedNames
     */
    private function parsePrefsArray(array $raw, array $allowedNames, string $resourceKey, string $source): UserPrefs
    {
        $columns = $this->parseColumns($raw['columns'] ?? null, $allowedNames, $resourceKey, $source);

        $rawDensity = $raw['density'] ?? null;
        $density = is_string($rawDensity) && in_array($rawDensity, $this->densityOptions(), true)
            ? $rawDensity
            : null;

        $rawPerPage = $raw['per_page'] ?? null;
        $perPage = null;
        if (is_int($rawPerPage) || (is_string($rawPerPage) && $rawPerPage !== '')) {
            $intVal = (int) $rawPerPage;
            if (in_array($intVal, $this->perPageOptions(), true)) {
                $perPage = $intVal;
            }
        }

        return new UserPrefs(columns: $columns, density: $density, perPage: $perPage);
    }

    /**
     * @param  array<int, string>  $allowedNames
     * @return array<int, string>|null
     */
    private function parseColumns(mixed $raw, array $allowedNames, string $resourceKey, string $source): ?array
    {
        if ($raw === null) {
            return null;
        }

        $list = null;
        if (is_string($raw)) {
            $list = array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '');
        } elseif (is_array($raw)) {
            $list = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $raw), fn ($v) => $v !== '');
        }

        if ($list === null) {
            return null;
        }

        $list = array_values($list);
        if ($list === []) {
            return null;
        }

        $allowedSet = array_flip($allowedNames);
        $valid = array_values(array_filter($list, fn (string $n) => isset($allowedSet[$n])));
        $rejected = array_values(array_diff($list, $valid));

        if ($rejected !== []) {
            Log::warning('tables.prefs.unknown_columns', [
                'resource' => $resourceKey,
                'source' => $source,
                'rejected' => $rejected,
            ]);
        }

        return $valid === [] ? null : $valid;
    }

    /**
     * @return array<int, int>
     */
    private function perPageOptions(): array
    {
        $opts = (array) config('tables.user_prefs.per_page_options', [15, 25, 50, 100]);

        return array_values(array_map('intval', $opts));
    }

    /**
     * @return array<int, string>
     */
    private function densityOptions(): array
    {
        $opts = (array) config('tables.user_prefs.density_options', ['compact', 'comfortable']);

        return array_values(array_map('strval', $opts));
    }
}
