<?php

namespace Mercurio\Tables\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\SavedView as SavedViewModel;
use Mercurio\Tables\Models\UserTablePrefs;
use Symfony\Component\HttpFoundation\Response;

// TODO 3.18: @internal — будет помечен в 3.18-public-api-freeze.
class UserViewHandler
{
    public function saveView(Request $request, ListResource $resource, ?string $routeBaseName): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        $palette = (array) config('tables.saved_view_color_palette', []);
        $iconList = (array) config('tables.saved_view_icons', []);

        $colorRule = $palette === []
            ? ['nullable', 'string', 'max:20']
            : ['nullable', 'string', 'max:20', 'in:'.implode(',', $palette)];
        $iconRule = $iconList === []
            ? ['nullable', 'string', 'max:60']
            : ['nullable', 'string', 'max:60', 'in:'.implode(',', $iconList)];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => $colorRule,
            'icon' => $iconRule,
            'state' => ['required', 'string', 'max:8192'],
        ]);

        $decoded = base64_decode($data['state'], true);
        if ($decoded === false) {
            return back()->withErrors(['state' => 'Некорректный state']);
        }
        try {
            $state = json_decode($decoded ?: '{}', true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return back()->withErrors(['state' => 'Некорректный state']);
        }
        if (! is_array($state)) {
            $state = [];
        }
        $whitelist = ['q', 'view', 'f', 'qb', 'sort', 'dir', 'columns', 'density', 'per_page'];
        $filtered = array_intersect_key($state, array_flip($whitelist));

        $resourceKey = $resource->key();

        $base = Str::slug($data['name']);
        if ($base === '') {
            $base = 'view';
        }
        $candidate = $base;
        $suffix = 1;
        while (
            SavedViewModel::query()
                ->forResource($resourceKey)
                ->forUser((int) $userId)
                ->where('key', $candidate)
                ->exists()
        ) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        $position = (int) (SavedViewModel::query()
            ->forResource($resourceKey)
            ->forUser((int) $userId)
            ->max('position') ?? 0) + 1;

        SavedViewModel::create([
            'user_id' => (int) $userId,
            'resource_key' => $resourceKey,
            'key' => $candidate,
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
            'position' => $position,
            'is_default' => false,
            'is_system' => false,
            'state_json' => $filtered,
        ]);

        Log::info('tables.savedviews.create', [
            'resource' => $resourceKey,
            'name' => $data['name'],
            'user_id' => $userId,
            'state_keys' => array_keys($filtered),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'Вид сохранён']);
        }

        return back()->with('status', 'Вид сохранён');
    }

    public function deleteUserView(Request $request, ListResource $resource, int $id): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        $view = SavedViewModel::query()
            ->where('id', $id)
            ->where('is_system', false)
            ->where('user_id', (int) $userId)
            ->firstOrFail();

        $view->delete();

        Log::info('tables.savedviews.delete', [
            'id' => $id,
            'user_id' => $userId,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'Вид удалён']);
        }

        return back()->with('status', 'Вид удалён');
    }

    public function savePrefs(Request $request, ListResource $resource): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        $perPageOptions = (array) config('tables.user_prefs.per_page_options', [15, 25, 50, 100]);
        $densityOptions = (array) config('tables.user_prefs.density_options', ['compact', 'comfortable']);

        $data = $request->validate([
            'columns' => ['nullable', 'array', 'min:1'],
            'columns.*' => ['string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'density' => ['nullable', 'string', Rule::in($densityOptions)],
            'per_page' => ['nullable', 'integer', Rule::in($perPageOptions)],
        ]);

        $allowed = array_map(fn ($f) => $f->name, $resource->fields());
        $submittedColumns = $data['columns'] ?? null;
        $cols = is_array($submittedColumns)
            ? array_values(array_intersect($submittedColumns, $allowed))
            : [];

        if ($submittedColumns !== null && $cols === []) {
            Log::warning('tables.prefs.empty_columns_after_whitelist', [
                'resource' => $resource->key(),
                'user_id' => $userId,
                'submitted' => $submittedColumns,
            ]);

            return response()->json([
                'errors' => ['columns' => ['Выберите хотя бы одно поле.']],
            ], 422);
        }

        $prefs = [];
        if ($cols !== []) {
            $prefs['columns'] = $cols;
        }
        if (isset($data['density'])) {
            $prefs['density'] = $data['density'];
        }
        if (isset($data['per_page'])) {
            $prefs['per_page'] = (int) $data['per_page'];
        }

        UserTablePrefs::upsertFor((int) $userId, $resource->key(), $prefs);

        Log::info('tables.prefs.saved', [
            'resource' => $resource->key(),
            'user_id' => $userId,
            'keys' => array_keys($prefs),
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Настройки сохранены',
        ]);
    }

    public function resetPrefs(Request $request, ListResource $resource): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        UserTablePrefs::query()
            ->forUser((int) $userId)
            ->forResource($resource->key())
            ->delete();

        Log::info('tables.prefs.reset', [
            'resource' => $resource->key(),
            'user_id' => $userId,
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Настройки сброшены',
        ]);
    }
}
