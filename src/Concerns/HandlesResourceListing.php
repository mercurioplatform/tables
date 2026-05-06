<?php

namespace Mercurio\Tables\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\SavedView as SavedViewModel;
use Mercurio\Tables\Models\UserTablePrefs;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait HandlesResourceListing
{
    public function index(Request $request): View
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $table = $resource->table($request);

        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        if ($partialHeader !== '' && $request->hasHeader($partialHeader)) {
            return view('tables::partial', ['table' => $table]);
        }

        return view($this->tableView, ['table' => $table]);
    }

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'q' => ['nullable', 'string', 'max:100'],
            'selected' => ['nullable', 'array'],
            'selected.*' => ['nullable'],
        ]);

        /** @var ListResource $resource */
        $resource = app($this->resource);
        $fieldName = $data['field'];
        $field = $resource->findField($fieldName);

        if ($field === null) {
            abort(404);
        }

        if (! $field->isFilterable() || ! $field->isFilterAutocomplete()) {
            abort(422);
        }

        $selected = array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $data['selected'] ?? []),
            fn ($v) => $v !== null && $v !== '',
        ));

        $q = $data['q'] ?? null;
        if (is_string($q) && $q === '') {
            $q = null;
        }

        $items = $resource->filterOptions($fieldName, $q, $request, $selected);

        Log::debug('tables.options', [
            'resource' => $this->resource,
            'field' => $fieldName,
            'q_len' => mb_strlen($q ?? ''),
            'selected' => count($selected),
            'count' => count($items),
        ]);

        $payload = [];
        foreach ($items as $value => $label) {
            $payload[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return response()->json(['items' => $payload]);
    }

    public function bulkAction(Request $request): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $name = (string) $request->input('action', '');
        $action = $this->findBulkAction($resource, $name);

        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        if ($action === null) {
            Log::warning('tables.bulk.unknown_action', [
                'resource' => $this->resource,
                'action' => $name,
            ]);

            return $this->bulkActionError($isXhr, "Неизвестное действие: {$name}", 404);
        }

        $kind = $action->getKind();
        $isForm = $kind === 'form';

        if (! $isForm && property_exists($this, 'bulkRequest') && $this->bulkRequest !== null) {
            $request = app($this->bulkRequest);
        }

        $ids = $this->extractBulkIds($request);

        if ($ids === []) {
            Log::warning('tables.bulk.empty_ids', [
                'resource' => $this->resource,
                'action' => $name,
                'kind' => $kind,
            ]);

            return $this->bulkActionError($isXhr, 'Не выбрано ни одного объекта.', 422);
        }

        $payload = [];
        $formRequestClass = $isForm ? $action->getFormRequest() : null;

        if ($isForm) {
            if ($formRequestClass !== null) {
                /** @var FormRequest $formRequest */
                $formRequest = app($formRequestClass);
                $payload = $formRequest->validated();
            }
        } else {
            $payload = $action->getPayload();
        }

        $handlerClass = $action->getHandler();
        $result = null;

        if ($handlerClass !== null) {
            try {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($ids, $payload);
            } catch (Throwable $e) {
                Log::error('tables.bulk.handler_threw', [
                    'resource' => $this->resource,
                    'action' => $name,
                    'kind' => $kind,
                    'handler' => $handlerClass,
                    'error' => $e->getMessage(),
                ]);

                return $this->bulkActionError($isXhr, 'Внутренняя ошибка. См. логи.', 500);
            }
        }

        Log::info('tables.bulk', [
            'resource' => $this->resource,
            'action' => $name,
            'kind' => $kind,
            'has_form_request' => $formRequestClass !== null,
            'ids_count' => count($ids),
            'affected' => $result?->affected,
            'missing' => $result?->missing,
        ]);

        $message = $result?->message ?? 'Обработано: '.($result?->affected ?? 0);

        if ($isXhr) {
            return response()->json([
                'status' => 'ok',
                'message' => $message,
                'reload' => $isForm ? $action->shouldReloadAfterSubmit() : true,
            ]);
        }

        return back()->with('status', $message);
    }

    public function bulkActionForm(Request $request, string $action): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $bulk = $this->findBulkAction($resource, $action);

        if ($bulk === null) {
            Log::warning('tables.bulk.form.unknown', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($bulk->getKind() !== 'form') {
            abort(404);
        }

        $ability = $bulk->getAbility();
        if ($ability !== null && ! Gate::check($ability)) {
            Log::warning('tables.bulk.form.forbidden', [
                'resource' => $this->resource,
                'action' => $action,
                'ability' => $ability,
            ]);
            abort(403);
        }

        $ids = $this->extractBulkIds($request);
        if ($ids === []) {
            Log::warning('tables.bulk.form.empty_ids', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(422, 'Не выбрано ни одного объекта.');
        }

        $forms = property_exists($this, 'bulkActionForms') && is_array($this->bulkActionForms)
            ? $this->bulkActionForms
            : [];

        $view = $forms[$action] ?? null;
        if ($view === null && property_exists($this, 'tableView') && is_string($this->tableView)) {
            $view = $this->tableView.'-bulk-'.$action;
        }

        if ($view === null || ! view()->exists($view)) {
            Log::warning('tables.bulk.form.view_missing', [
                'resource' => $this->resource,
                'action' => $action,
                'view' => $view,
            ]);
            abort(404);
        }

        $base = $this->deriveBaseRouteName();
        $submitUrl = route($base.'.bulk_action');

        Log::debug('tables.bulk.form_open', [
            'resource' => $this->resource,
            'action' => $action,
            'ids_count' => count($ids),
            'view' => $view,
        ]);

        return response()->view($view, [
            'action' => $bulk,
            'ids' => $ids,
            'idsCount' => count($ids),
            'submitUrl' => $submitUrl,
        ]);
    }

    private function bulkActionError(bool $isXhr, string $message, int $status): Response
    {
        if ($isXhr) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['action' => $message]);
    }

    public function saveView(Request $request): Response
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

        /** @var ListResource $resource */
        $resource = app($this->resource);
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

    public function savePrefs(Request $request): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        /** @var ListResource $resource */
        $resource = app($this->resource);

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

    public function resetPrefs(Request $request): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        /** @var ListResource $resource */
        $resource = app($this->resource);

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

    public function deleteUserView(Request $request, int $id): Response
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

    private function findBulkAction(ListResource $resource, string $name): ?BulkAction
    {
        foreach ($resource->bulkActions() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    public function rowAction(Request $request, $id, string $action): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $rowAction = $this->findRowAction($resource, $action);
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        if ($rowAction === null) {
            Log::warning('tables.rowaction.unknown', [
                'resource' => $this->resource,
                'action' => $action,
            ]);

            return $this->rowActionError($request, $isXhr, "Неизвестное действие: {$action}", 404);
        }

        $model = $resource->query()->whereKey($id)->first();
        if ($model === null) {
            Log::warning('tables.rowaction.missing', [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
            ]);

            return $this->rowActionError($request, $isXhr, 'Запись не найдена.', 404);
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.rowaction.hidden', [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
            ]);

            return $this->rowActionError($request, $isXhr, 'Действие недоступно.', 403);
        }

        $ability = $rowAction->getAbility() ?? 'update';
        if (! Gate::check($ability, $model)) {
            Log::warning('tables.rowaction.forbidden', [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
                'ability' => $ability,
            ]);
            abort(403);
        }

        $payload = $this->resolveRowActionPayload($request, $rowAction);

        $handlerClass = $rowAction->getHandler();
        if ($handlerClass === null && $rowAction->getKind() !== 'link') {
            Log::warning('tables.rowaction.no_handler', [
                'resource' => $this->resource,
                'action' => $action,
            ]);

            return $this->rowActionError($request, $isXhr, 'Действие не настроено.', 500);
        }

        $result = null;
        try {
            /** @var Action $handler */
            $handler = app($handlerClass);
            $result = $handler->execute($model, $payload);
        } catch (Throwable $e) {
            Log::error('tables.rowaction.handler_threw', [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
                'handler' => $handlerClass,
                'error' => $e->getMessage(),
            ]);

            return $this->rowActionError($request, $isXhr, 'Внутренняя ошибка. См. логи.', 500);
        }

        Log::info('tables.rowaction.executed', [
            'resource' => $this->resource,
            'action' => $action,
            'id' => $id,
            'kind' => $rowAction->getKind(),
            'affected' => $result?->affected,
            'message' => $result?->message,
        ]);

        $message = $result?->message ?? 'Готово';

        if ($isXhr) {
            return response()->json([
                'status' => 'ok',
                'message' => $message,
                'reload' => $rowAction->shouldReloadAfterSubmit(),
            ]);
        }

        return back()->with('status', $message);
    }

    public function rowActionForm(Request $request, $id, string $action): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $rowAction = $this->findRowAction($resource, $action);
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        if ($rowAction === null) {
            Log::warning('tables.rowaction.form.unknown', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($rowAction->getKind() !== 'form') {
            abort(404);
        }

        $model = $resource->query()->whereKey($id)->first();
        if ($model === null) {
            abort(404);
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.rowaction.form.hidden', [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
            ]);
            abort(403);
        }

        $ability = $rowAction->getAbility() ?? 'update';
        if (! Gate::check($ability, $model)) {
            abort(403);
        }

        $forms = property_exists($this, 'rowActionForms') && is_array($this->rowActionForms)
            ? $this->rowActionForms
            : [];

        $view = $forms[$action] ?? null;
        if ($view === null && property_exists($this, 'tableView') && is_string($this->tableView)) {
            $view = $this->tableView.'-row-action-'.$action;
        }

        if ($view === null || ! view()->exists($view)) {
            Log::warning('tables.rowaction.form.view_missing', [
                'resource' => $this->resource,
                'action' => $action,
                'view' => $view,
            ]);
            abort(404);
        }

        $base = $this->deriveBaseRouteName();
        $submitUrl = route($base.'.row_action', ['id' => $id, 'action' => $action]);

        Log::debug('tables.rowaction.form_open', [
            'resource' => $this->resource,
            'action' => $action,
            'id' => $id,
            'view' => $view,
        ]);

        return response()->view($view, [
            'model' => $model,
            'action' => $rowAction,
            'submitUrl' => $submitUrl,
        ]);
    }

    private function findRowAction(ListResource $resource, string $name): ?RowAction
    {
        foreach ($resource->rowActions() as $action) {
            if ($action instanceof RowAction && $action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRowActionPayload(Request $request, RowAction $action): array
    {
        if ($action->getKind() !== 'form') {
            return [];
        }

        $formRequestClass = $action->getFormRequest();
        if ($formRequestClass === null) {
            return [];
        }

        /** @var FormRequest $formRequest */
        $formRequest = app($formRequestClass);

        return $formRequest->validated();
    }

    private function deriveBaseRouteName(): string
    {
        if (property_exists($this, 'routeBaseName') && is_string($this->routeBaseName) && $this->routeBaseName !== '') {
            return $this->routeBaseName;
        }

        $current = (string) (Route::currentRouteName() ?? '');
        if ($current === '') {
            return '';
        }

        foreach (['.row_action_form', '.bulk_action_form', '.row_action', '.index', '.bulk_action', '.options', '.save_view', '.delete_user_view', '.save_prefs', '.reset_prefs'] as $suffix) {
            if (str_ends_with($current, $suffix)) {
                return substr($current, 0, -strlen($suffix));
            }
        }

        $pos = strrpos($current, '.');

        return $pos === false ? $current : substr($current, 0, $pos);
    }

    private function rowActionError(Request $request, bool $isXhr, string $message, int $status): Response
    {
        if ($isXhr) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['action' => $message]);
    }

    /**
     * @return array<int, mixed>
     */
    private function extractBulkIds(Request $request): array
    {
        $raw = $request->input('ids', []);

        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), fn ($v) => $v !== '');
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique($raw));
    }
}
