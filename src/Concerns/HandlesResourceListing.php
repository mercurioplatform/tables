<?php

namespace Mercurio\Tables\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Export\CsvStreamWriter;
use Mercurio\Tables\Export\ExportJobDispatcher;
use Mercurio\Tables\Export\ExportRequest;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Form\Field\FieldRow;
use Mercurio\Tables\Form\Field\FormField;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\SavedView as SavedViewModel;
use Mercurio\Tables\Models\UserTablePrefs;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        $tableView = property_exists($this, 'tableView') && is_string($this->tableView) && $this->tableView !== ''
            ? $this->tableView
            : null;

        $bulkActionUrl = $this->resolveBulkActionUrl($resource);

        if ($tableView !== null && view()->exists($tableView)) {
            return view($tableView, ['table' => $table, 'bulkActionUrl' => $bulkActionUrl]);
        }

        Log::debug('tables.shell.render', [
            'resource' => $this->resource,
            'has_title' => $resource->pageTitle() !== null,
            'has_subtitle' => $resource->subtitle($table->paginator->total()) !== null,
            'crumbs' => count($resource->breadcrumbs()),
            'actions' => count($resource->headerActions()),
            'layout' => $resource->layout(),
        ]);

        return view('tables::shell', ['table' => $table, 'bulkActionUrl' => $bulkActionUrl]);
    }

    private function resolveBulkActionUrl(ListResource $resource): string
    {
        $base = $resource->routeBaseName() ?? $this->deriveBaseRouteName();
        if ($base === '') {
            return '';
        }

        $routeName = $base.'.bulk_action';
        if (! Route::has($routeName)) {
            return '';
        }

        return route($routeName);
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

        if ($action->hasPolicy() || $action->getAbility() !== null) {
            $probe = $resource->query()->whereKey($ids[0])->first();
            if ($probe === null) {
                Log::warning('tables.bulk.probe_missing', [
                    'resource' => $this->resource,
                    'action' => $name,
                    'kind' => $kind,
                    'probe_id' => $ids[0],
                ]);

                return $this->bulkActionError($isXhr, 'Запись не найдена.', 404);
            }
            if (! $this->authorizeAction($action, $probe, 'bulk')) {
                if (! $action->hasPolicy()) {
                    Log::warning('tables.bulk.forbidden', [
                        'resource' => $this->resource,
                        'action' => $name,
                        'kind' => $kind,
                        'ability' => $action->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $payload = [];
        $validationSource = 'none';
        $formRequestClass = $isForm ? $action->getFormRequest() : null;

        if ($isForm) {
            if ($formRequestClass !== null) {
                /** @var FormRequest $formRequest */
                $formRequest = app($formRequestClass);
                $payload = $formRequest->validated();
                $validationSource = 'form_request';
            } else {
                $resolved = $this->resolveSchemaPayload($action, $request);
                $payload = $resolved['payload'];
                $validationSource = $resolved['source'];
            }
        } else {
            $payload = $action->getPayload();
        }

        $callback = $action->getCallback();
        $handlerClass = $action->getHandler();
        $mode = $action->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');
        $result = null;

        Log::debug('tables.action.dispatch', [
            'resource' => $this->resource,
            'kind' => 'bulk',
            'action' => $name,
            'mode' => $mode,
        ]);

        if ($mode === 'callback') {
            try {
                $result = $callback($ids, $payload, $this->currentTableActor());
            } catch (Throwable $e) {
                Log::error('tables.bulk.callback_threw', [
                    'resource' => $this->resource,
                    'action' => $name,
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ]);

                return $this->flashFromException($e, $action, $isXhr);
            }
        } elseif ($mode === 'handler') {
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

                return $this->flashFromException($e, $action, $isXhr);
            }
        }

        Log::info('tables.bulk', [
            'resource' => $this->resource,
            'action' => $name,
            'kind' => $kind,
            'mode' => $mode,
            'authz' => $this->authzMode($action),
            'has_form_request' => $formRequestClass !== null,
            'validation' => $validationSource,
            'ids_count' => count($ids),
            'affected' => $result?->affected,
            'missing' => $result?->missing,
        ]);

        $reload = $isForm ? $action->shouldReloadAfterSubmit() : true;

        return $this->flashFromActionResult(
            result: $result ?? new ActionResult(affected: 0),
            action: $action,
            isXhr: $isXhr,
            reload: $reload,
        );
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

        $ids = $this->extractBulkIds($request);
        if ($ids === []) {
            Log::warning('tables.bulk.form.empty_ids', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(422, 'Не выбрано ни одного объекта.');
        }

        if ($bulk->hasPolicy() || $bulk->getAbility() !== null) {
            $probe = $resource->query()->whereKey($ids[0])->first();
            if ($probe === null) {
                Log::warning('tables.bulk.form.probe_missing', [
                    'resource' => $this->resource,
                    'action' => $action,
                    'probe_id' => $ids[0],
                ]);
                abort(404, 'Запись не найдена.');
            }
            if (! $this->authorizeAction($bulk, $probe, 'bulk')) {
                if (! $bulk->hasPolicy()) {
                    Log::warning('tables.bulk.form.forbidden', [
                        'resource' => $this->resource,
                        'action' => $action,
                        'ability' => $bulk->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $base = $this->deriveBaseRouteName();
        $submitUrl = route($base.'.bulk_action');

        if ($bulk->hasSchema()) {
            Log::debug('tables.form.render', [
                'resource' => $this->resource,
                'action' => $action,
                'kind' => 'bulk',
                'ids_count' => count($ids),
                'schema_count' => count($bulk->getSchema()),
            ]);

            return response()->view('tables::auto-bulk-form', [
                'action' => $bulk,
                'ids' => $ids,
                'idsCount' => count($ids),
                'submitUrl' => $submitUrl,
                'schema' => $bulk->getSchema(),
            ]);
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

    public function bulkActionPreview(Request $request, string $action): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $bulk = $this->findBulkAction($resource, $action);

        if ($bulk === null) {
            Log::warning('tables.confirm.preview.unknown', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($bulk->getKind() !== 'confirm') {
            Log::warning('tables.confirm.preview.kind_mismatch', [
                'resource' => $this->resource,
                'action' => $action,
                'kind' => $bulk->getKind(),
            ]);
            abort(404);
        }

        if (! $bulk->hasPreview()) {
            Log::warning('tables.confirm.preview.no_callback', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(404);
        }

        $ids = $this->extractBulkIds($request);
        if ($ids === []) {
            Log::warning('tables.confirm.preview.empty_ids', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(422, 'Не выбрано ни одного объекта.');
        }

        if ($bulk->hasPolicy() || $bulk->getAbility() !== null) {
            $probe = $resource->query()->whereKey($ids[0])->first();
            if ($probe === null) {
                Log::warning('tables.confirm.preview.probe_missing', [
                    'resource' => $this->resource,
                    'action' => $action,
                    'probe_id' => $ids[0],
                ]);
                abort(404);
            }
            if (! $this->authorizeAction($bulk, $probe, 'bulk')) {
                if (! $bulk->hasPolicy()) {
                    Log::warning('tables.confirm.preview.forbidden', [
                        'resource' => $this->resource,
                        'action' => $action,
                        'ability' => $bulk->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $payload = $bulk->getPayload();
        $cb = $bulk->getPreviewCallback();

        try {
            $result = $cb($ids, $payload);
        } catch (Throwable $e) {
            Log::error('tables.confirm.preview.callback_threw', [
                'resource' => $this->resource,
                'action' => $action,
                'kind' => 'bulk',
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Не удалось построить превью.');
        }

        $html = $this->renderActionPreview($result);
        $base = $this->deriveBaseRouteName();
        $submitUrl = route($base.'.bulk_action');

        Log::debug('tables.confirm.preview.render', [
            'resource' => $this->resource,
            'action' => $action,
            'kind' => 'bulk',
            'subjects_count' => count($ids),
            'return_type' => $this->actionPreviewReturnType($result),
        ]);

        return response()->view('tables::confirm-preview', [
            'kind' => 'bulk',
            'action' => $bulk,
            'ids' => $ids,
            'idsCount' => count($ids),
            'submitUrl' => $submitUrl,
            'html' => $html,
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

    /**
     * Построить Response из ActionResult с учётом onSuccess()-callback action'а.
     *
     * @param  BulkAction|RowAction  $action
     */
    private function flashFromActionResult(
        ActionResult $result,
        $action,
        bool $isXhr,
        bool $reload,
    ): Response {
        $payload = null;
        $via = 'default';

        if ($action->hasOnSuccess()) {
            try {
                $payload = ($action->getOnSuccessCallback())($result);
                $via = 'callback';
            } catch (Throwable $e) {
                Log::error('tables.action.flash.success_callback_threw', [
                    'resource' => $this->resource,
                    'action' => $action->name,
                    'error' => $e->getMessage(),
                ]);
                $payload = null;
            }
        }

        if ($payload === null) {
            $defaultMsg = $action instanceof BulkAction
                ? 'Обработано: '.$result->affected
                : 'Готово';
            if ($action->hasOnSuccess()) {
                $payload = $defaultMsg;
                $via = 'default';
            } elseif ($result->message !== null) {
                $payload = $result->message;
                $via = 'legacy_message';
            } else {
                $payload = $defaultMsg;
                $via = 'default';
            }
        }

        return $this->buildFlashResponse(
            payload: $payload,
            isXhr: $isXhr,
            httpStatus: 200,
            reload: $reload,
            primaryKind: 'success',
            via: $via,
        );
    }

    /**
     * Построить Response из Throwable с учётом onError()-callback action'а.
     *
     * @param  BulkAction|RowAction  $action
     */
    private function flashFromException(
        Throwable $e,
        $action,
        bool $isXhr,
    ): Response {
        $payload = null;
        $via = 'default';

        if ($action->hasOnError()) {
            try {
                $payload = ($action->getOnErrorCallback())($e);
                $via = 'callback';
            } catch (Throwable $cbErr) {
                Log::error('tables.action.flash.error_callback_threw', [
                    'resource' => $this->resource,
                    'action' => $action->name,
                    'original_error' => $e->getMessage(),
                    'callback_error' => $cbErr->getMessage(),
                ]);
                $payload = null;
            }
        }

        if ($payload === null) {
            $payload = ['error' => 'Внутренняя ошибка. См. логи.'];
        }

        return $this->buildFlashResponse(
            payload: $payload,
            isXhr: $isXhr,
            httpStatus: 500,
            reload: false,
            primaryKind: 'error',
            via: $via,
        );
    }

    /**
     * Низкоуровневый builder. Нормализует payload (string|array) → массив ключей
     * status/warning/error/counts и строит либо JSON (XHR) либо back()->with(...) (redirect).
     *
     * @param  string|array<string, mixed>  $payload
     * @param  'success'|'error'  $primaryKind  «куда положить string» — success → status, error → error
     */
    private function buildFlashResponse(
        string|array $payload,
        bool $isXhr,
        int $httpStatus,
        bool $reload,
        string $primaryKind,
        string $via,
    ): Response {
        $normalized = ['status' => null, 'warning' => null, 'error' => null, 'counts' => null];

        if (is_string($payload)) {
            $key = $primaryKind === 'success' ? 'status' : 'error';
            $normalized[$key] = $payload;
        } else {
            $allowed = ['status', 'warning', 'error', 'counts'];
            foreach ($payload as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $normalized[$k] = $v;
                } else {
                    Log::warning('tables.action.flash.unknown_key', [
                        'resource' => $this->resource,
                        'key' => $k,
                    ]);
                }
            }
        }

        $usedTypes = array_values(array_filter(
            ['status', 'warning', 'error'],
            fn ($k) => $normalized[$k] !== null && $normalized[$k] !== ''
        ));

        Log::debug('tables.action.flash.built', [
            'resource' => $this->resource,
            'via' => $via,
            'types' => $usedTypes,
            'has_counts' => $normalized['counts'] !== null,
            'is_xhr' => $isXhr,
        ]);

        if ($isXhr) {
            $primary = $normalized[$primaryKind === 'success' ? 'status' : 'error']
                ?? $normalized['warning']
                ?? '';

            return response()->json([
                'status' => $primaryKind === 'success' ? 'ok' : 'error',
                'message' => $primary,
                'flash' => array_filter($normalized, fn ($v) => $v !== null && $v !== ''),
                'reload' => $reload,
            ], $httpStatus);
        }

        $redirect = back();
        foreach (['status', 'warning'] as $key) {
            if ($normalized[$key] !== null && $normalized[$key] !== '') {
                $redirect->with($key, $normalized[$key]);
            }
        }
        if ($normalized['error'] !== null && $normalized['error'] !== '') {
            $redirect->withErrors(['action' => $normalized['error']]);
        }

        return $redirect;
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

    public function export(Request $request): Response
    {
        $guard = (string) config('tables.guard', 'web');
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        $ability = config('tables.export.ability');
        if ($ability !== null && ! Gate::check($ability)) {
            Log::warning('tables.export.forbidden', [
                'resource' => $this->resource,
                'user_id' => $userId,
                'ability' => $ability,
            ]);
            abort(403);
        }

        /** @var ListResource $resource */
        $resource = app($this->resource);
        $state = $resource->exportState($request);
        $total = (int) $state['total'];
        $columns = $state['columns'];
        $builder = $state['builder'];

        if ($columns === []) {
            Log::warning('tables.export.no_columns', ['resource' => $this->resource]);
            abort(422, 'Нет колонок для экспорта.');
        }

        $syncLimit = (int) config('tables.export.sync_limit', 10000);
        if ($total > $syncLimit) {
            $dispatcherClass = config('tables.export.async_dispatcher');
            if (is_string($dispatcherClass) && $dispatcherClass !== '' && class_exists($dispatcherClass)) {
                try {
                    /** @var ExportJobDispatcher $dispatcher */
                    $dispatcher = app($dispatcherClass);
                    $jobId = $dispatcher->dispatch(
                        $this->resource,
                        (array) $state['queryParams'],
                        (int) $userId,
                        $total,
                    );
                    Log::info('tables.export.dispatched', [
                        'resource' => $this->resource,
                        'user_id' => $userId,
                        'rows' => $total,
                        'job_id' => $jobId,
                    ]);

                    return response()->json([
                        'status' => 'queued',
                        'message' => "Экспорт ({$total} строк) запущен в фоне. Уведомление придёт по готовности.",
                        'job_id' => $jobId,
                    ], 202);
                } catch (Throwable $e) {
                    Log::error('tables.export.dispatch_failed', [
                        'resource' => $this->resource,
                        'error' => $e->getMessage(),
                    ]);
                    abort(500, 'Не удалось запустить фоновый экспорт.');
                }
            }

            Log::warning('tables.export.too_large', [
                'resource' => $this->resource,
                'user_id' => $userId,
                'rows' => $total,
                'sync_limit' => $syncLimit,
            ]);

            return response()->json([
                'status' => 'too_large',
                'message' => "Слишком много строк для экспорта ({$total}). Лимит: {$syncLimit}. Уточните фильтр или обратитесь к администратору.",
            ], 413);
        }

        $exportRequest = new ExportRequest(
            filename: $this->buildExportFilename($resource->key()),
            delimiter: (string) config('tables.export.csv_delimiter', ','),
            enclosure: (string) config('tables.export.csv_enclosure', '"'),
            escape: (string) config('tables.export.csv_escape', '\\'),
            bom: (bool) config('tables.export.csv_bom', true),
            chunkSize: (int) config('tables.export.chunk_size', 500),
            logChunks: (bool) config('tables.export.log_chunks', false),
            columns: $columns,
        );

        Log::info('tables.export.start', [
            'resource' => $this->resource,
            'user_id' => $userId,
            'rows' => $total,
            'columns' => array_map(fn (Field $f) => $f->name, $columns),
            'filename' => $exportRequest->filename,
        ]);

        $logger = function (string $event, array $ctx) use ($exportRequest): void {
            Log::info('tables.export.'.$event, ['filename' => $exportRequest->filename] + $ctx);
        };

        return new StreamedResponse(
            function () use ($exportRequest, $builder, $logger): void {
                try {
                    CsvStreamWriter::stream($exportRequest, $builder, $logger);
                } catch (Throwable $e) {
                    Log::error('tables.export.error', [
                        'filename' => $exportRequest->filename,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$exportRequest->filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function buildExportFilename(string $resourceKey): string
    {
        $prefix = (string) config('tables.export.filename_prefix', '');
        $slug = Str::slug(str_replace('.', '-', $resourceKey));
        $stamp = now()->format('Ymd-Hi');

        return ltrim($prefix.$slug.'-'.$stamp.'.csv', '-');
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

    public function cellUpdate(Request $request, $id, string $field): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        $f = $resource->findField($field);
        if ($f === null || ! $f->isEditable()) {
            Log::warning('tables.cell.not_editable', [
                'resource' => $this->resource,
                'field' => $field,
            ]);

            return response()->json([
                'message' => 'Поле недоступно для inline-редактирования.',
            ], 422);
        }

        $model = $resource->query()->whereKey($id)->first();
        if ($model === null) {
            Log::warning('tables.cell.update.missing', [
                'resource' => $this->resource,
                'field' => $field,
                'id' => $id,
            ]);
            abort(404);
        }

        $policy = $f->getEditPolicy();
        if ($policy !== null) {
            $actor = $this->currentTableActor();
            $allowed = (bool) Gate::forUser($actor)->check($policy['method'], $model);
            if (! $allowed) {
                Log::warning('tables.cell.update.forbidden', [
                    'resource' => $this->resource,
                    'field' => $field,
                    'id' => $id,
                    'policy_class' => $policy['class'],
                    'policy_method' => $policy['method'],
                    'actor_id' => $actor?->getAuthIdentifier(),
                ]);
                abort(403);
            }
        }

        $rules = $f->compileEditRules($model);
        $validator = Validator::make(
            ['value' => $request->input('value')],
            ['value' => $rules],
        );

        if ($validator->fails()) {
            Log::warning('tables.cell.update.validation_failed', [
                'resource' => $this->resource,
                'field' => $field,
                'id' => $id,
                'errors' => array_keys($validator->errors()->toArray()),
            ]);

            return response()->json([
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $value = $validator->validated()['value'] ?? null;
        $column = $f->getEditableColumn();
        $oldValue = $model->{$column} ?? null;

        DB::transaction(fn () => $model->update([$column => $value]));

        Log::info('tables.cell.update', [
            'resource' => $this->resource,
            'field' => $field,
            'column' => $column,
            'id' => $id,
            'old' => $oldValue,
            'new' => $value,
            'actor_id' => $this->currentTableActor()?->getAuthIdentifier(),
            'is_xhr' => $isXhr,
        ]);

        $fresh = $resource->query()->whereKey($id)->first();
        $table = $resource->table($request);

        return response()
            ->view('tables::row-fragment', [
                'table' => $table,
                'row' => $fresh,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
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

    /**
     * Единый authz-резолвер для bulk/row-actions.
     * Приоритет: policy(class, method) → ability(name) → open (true).
     * Логирует tables.policy.check (debug) и tables.policy.denied (warning) только для policy-пути.
     */
    private function authorizeAction(BulkAction|RowAction $action, mixed $subject, string $kind): bool
    {
        $policy = $action->getPolicy();
        if ($policy !== null) {
            $actor = $this->currentTableActor();
            $allowed = (bool) Gate::forUser($actor)->check($policy['method'], $subject);

            $subjectClass = is_object($subject) ? get_class($subject) : null;
            $subjectKey = is_object($subject) && method_exists($subject, 'getKey')
                ? $subject->getKey()
                : null;

            Log::debug('tables.policy.check', [
                'resource' => $this->resource,
                'action' => $action->name,
                'kind' => $kind,
                'policy_class' => $policy['class'],
                'policy_method' => $policy['method'],
                'subject_class' => $subjectClass,
                'subject_key' => $subjectKey,
                'allowed' => $allowed,
                'actor_id' => $actor?->getAuthIdentifier(),
            ]);

            if (! $allowed) {
                Log::warning('tables.policy.denied', [
                    'resource' => $this->resource,
                    'action' => $action->name,
                    'kind' => $kind,
                    'policy_class' => $policy['class'],
                    'policy_method' => $policy['method'],
                    'subject_class' => $subjectClass,
                    'subject_key' => $subjectKey,
                    'actor_id' => $actor?->getAuthIdentifier(),
                ]);
            }

            return $allowed;
        }

        $ability = $action->getAbility();
        if ($ability !== null) {
            return Gate::check($ability, $subject);
        }

        return true;
    }

    private function currentTableActor(): ?Authenticatable
    {
        $guard = (string) config('tables.guard', 'web');

        return Auth::guard($guard)->user();
    }

    private function authzMode(BulkAction|RowAction $action): string
    {
        return match (true) {
            $action->hasPolicy() => 'policy',
            $action->getAbility() !== null => 'ability',
            default => 'open',
        };
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

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizeAction($rowAction, $model, 'row')) {
                if (! $rowAction->hasPolicy()) {
                    Log::warning('tables.rowaction.forbidden', [
                        'resource' => $this->resource,
                        'action' => $action,
                        'id' => $id,
                        'ability' => $rowAction->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $resolved = $this->resolveRowActionPayload($request, $rowAction);
        $payload = $resolved['payload'];
        $validationSource = $resolved['source'];

        $callback = $rowAction->getCallback();
        $handlerClass = $rowAction->getHandler();
        $mode = $rowAction->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');

        if ($mode === 'none' && $rowAction->getKind() !== 'link') {
            Log::warning('tables.rowaction.no_handler', [
                'resource' => $this->resource,
                'action' => $action,
            ]);

            return $this->rowActionError($request, $isXhr, 'Действие не настроено.', 500);
        }

        Log::debug('tables.action.dispatch', [
            'resource' => $this->resource,
            'kind' => 'row',
            'action' => $action,
            'mode' => $mode,
        ]);

        $result = null;
        try {
            if ($mode === 'callback') {
                $result = $callback($model, $payload, $this->currentTableActor());
            } elseif ($mode === 'handler') {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($model, $payload);
            }
        } catch (Throwable $e) {
            $logKey = $mode === 'callback' ? 'tables.rowaction.callback_threw' : 'tables.rowaction.handler_threw';
            Log::error($logKey, [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
                'handler' => $handlerClass,
                'error' => $e->getMessage(),
            ]);

            return $this->flashFromException($e, $rowAction, $isXhr);
        }

        Log::info('tables.rowaction.executed', [
            'resource' => $this->resource,
            'action' => $action,
            'id' => $id,
            'kind' => $rowAction->getKind(),
            'mode' => $mode,
            'authz' => $this->authzMode($rowAction),
            'validation' => $validationSource,
            'affected' => $result?->affected,
            'message' => $result?->message,
        ]);

        return $this->flashFromActionResult(
            result: $result ?? new ActionResult(affected: 0),
            action: $rowAction,
            isXhr: $isXhr,
            reload: $rowAction->shouldReloadAfterSubmit(),
        );
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

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizeAction($rowAction, $model, 'row')) {
                if (! $rowAction->hasPolicy()) {
                    Log::warning('tables.rowaction.form.forbidden', [
                        'resource' => $this->resource,
                        'action' => $action,
                        'id' => $id,
                        'ability' => $rowAction->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $base = $this->deriveBaseRouteName();
        $submitUrl = route($base.'.row_action', ['id' => $id, 'action' => $action]);

        if ($rowAction->hasSchema()) {
            Log::debug('tables.form.render', [
                'resource' => $this->resource,
                'action' => $action,
                'kind' => 'row',
                'id' => $id,
                'schema_count' => count($rowAction->getSchema()),
            ]);

            return response()->view('tables::auto-row-form', [
                'action' => $rowAction,
                'model' => $model,
                'submitUrl' => $submitUrl,
                'schema' => $rowAction->getSchema(),
            ]);
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

    public function rowActionPreview(Request $request, $id, string $action): Response
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $rowAction = $this->findRowAction($resource, $action);

        if ($rowAction === null) {
            Log::warning('tables.confirm.preview.unknown', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($rowAction->getKind() !== 'confirm') {
            Log::warning('tables.confirm.preview.kind_mismatch', [
                'resource' => $this->resource,
                'action' => $action,
                'kind' => $rowAction->getKind(),
            ]);
            abort(404);
        }

        if (! $rowAction->hasPreview()) {
            Log::warning('tables.confirm.preview.no_callback', [
                'resource' => $this->resource,
                'action' => $action,
            ]);
            abort(404);
        }

        $model = $resource->query()->whereKey($id)->first();
        if ($model === null) {
            abort(404);
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.confirm.preview.hidden', [
                'resource' => $this->resource,
                'action' => $action,
                'id' => $id,
            ]);
            abort(403);
        }

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizeAction($rowAction, $model, 'row')) {
                if (! $rowAction->hasPolicy()) {
                    Log::warning('tables.confirm.preview.forbidden', [
                        'resource' => $this->resource,
                        'action' => $action,
                        'id' => $id,
                        'ability' => $rowAction->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $payload = $this->resolveRowActionPayload($request, $rowAction);
        $cb = $rowAction->getPreviewCallback();

        try {
            $result = $cb($model, $payload);
        } catch (Throwable $e) {
            Log::error('tables.confirm.preview.callback_threw', [
                'resource' => $this->resource,
                'action' => $action,
                'kind' => 'row',
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Не удалось построить превью.');
        }

        $html = $this->renderActionPreview($result);
        $base = $this->deriveBaseRouteName();
        $submitUrl = route($base.'.row_action', ['id' => $id, 'action' => $action]);

        Log::debug('tables.confirm.preview.render', [
            'resource' => $this->resource,
            'action' => $action,
            'kind' => 'row',
            'id' => $id,
            'return_type' => $this->actionPreviewReturnType($result),
        ]);

        return response()->view('tables::confirm-preview', [
            'kind' => 'row',
            'action' => $rowAction,
            'model' => $model,
            'submitUrl' => $submitUrl,
            'html' => $html,
        ]);
    }

    private function renderActionPreview(\Illuminate\Contracts\View\View|string|array $result): string
    {
        if ($result instanceof \Illuminate\Contracts\View\View) {
            return $result->render();
        }

        if (is_string($result)) {
            return $result;
        }

        return view('tables::confirm-preview-default', ['data' => $result])->render();
    }

    private function actionPreviewReturnType(mixed $result): string
    {
        return match (true) {
            $result instanceof \Illuminate\Contracts\View\View => 'view',
            is_string($result) => 'string',
            is_array($result) => 'array',
            default => 'unknown',
        };
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
     * @return array{payload: array<string, mixed>, source: string}
     */
    private function resolveRowActionPayload(Request $request, RowAction $action): array
    {
        if ($action->getKind() !== 'form') {
            return ['payload' => [], 'source' => 'none'];
        }

        $formRequestClass = $action->getFormRequest();
        if ($formRequestClass !== null) {
            /** @var FormRequest $formRequest */
            $formRequest = app($formRequestClass);

            return ['payload' => $formRequest->validated(), 'source' => 'form_request'];
        }

        return $this->resolveSchemaPayload($action, $request);
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

        foreach (['.row_action_preview', '.bulk_action_preview', '.row_action_form', '.bulk_action_form', '.row_action', '.index', '.bulk_action', '.options', '.save_view', '.delete_user_view', '.save_prefs', '.reset_prefs', '.export', '.cell_update'] as $suffix) {
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

    /**
     * @return array{payload: array<string, mixed>, source: string}
     */
    private function resolveSchemaPayload(BulkAction|RowAction $action, Request $request): array
    {
        if (! $action->hasSchema()) {
            return ['payload' => [], 'source' => 'none'];
        }

        $fields = $this->flattenSchemaFields($action->getSchema());
        if ($fields === []) {
            return ['payload' => [], 'source' => 'none'];
        }

        $rules = [];
        $messages = [];
        $attributes = [];
        $hasAnyRules = false;

        foreach ($fields as $field) {
            $compiled = $field->compileRules();
            if ($compiled === []) {
                continue;
            }
            $hasAnyRules = true;
            $rules[$field->name] = $compiled;
            foreach ($field->getMessages() as $key => $msg) {
                $messages[$field->name.'.'.$key] = $msg;
            }
            $attributes[$field->name] = $field->getAttribute() ?? $field->label;
        }

        $prepareHook = $action->getPrepareInputHook();
        $withValidatorHook = $action->getWithValidatorHook();
        $transformHook = $action->getTransformValidatedHook();

        if (! $hasAnyRules && $prepareHook === null && $withValidatorHook === null && $transformHook === null) {
            return [
                'payload' => $this->collectSchemaInput($fields, $request),
                'source' => 'none',
            ];
        }

        $input = $request->all();
        if ($prepareHook !== null) {
            $input = $prepareHook($input);
        }

        $validator = Validator::make($input, $rules, $messages, $attributes);
        if ($withValidatorHook !== null) {
            $withValidatorHook($validator, $input);
        }

        Log::debug('tables.form.validate', [
            'resource' => $this->resource,
            'action' => $action->name,
            'has_rules' => $hasAnyRules,
            'has_prepare_hook' => $prepareHook !== null,
            'has_after_hook' => $withValidatorHook !== null,
            'has_transform_hook' => $transformHook !== null,
            'fields_count' => count($fields),
        ]);

        try {
            $validated = $validator->validate();
        } catch (ValidationException $e) {
            Log::warning('tables.form.validation_failed', [
                'resource' => $this->resource,
                'action' => $action->name,
                'errors' => array_keys($e->errors()),
            ]);
            throw $e;
        }

        $schemaKeys = array_map(fn (FormField $f) => $f->name, $fields);
        $validated = array_intersect_key($validated, array_flip($schemaKeys));

        if ($transformHook !== null) {
            $validated = $transformHook($validated);
        }

        return ['payload' => $validated, 'source' => 'schema'];
    }

    /**
     * @param  array<int, FormField|FieldRow>  $schema
     * @return array<int, FormField>
     */
    private function flattenSchemaFields(array $schema): array
    {
        $out = [];
        foreach ($schema as $entry) {
            if ($entry instanceof FieldRow) {
                foreach ($entry->fields as $f) {
                    $out[] = $f;
                }
            } elseif ($entry instanceof FormField) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, FormField>  $fields
     * @return array<string, mixed>
     */
    private function collectSchemaInput(array $fields, Request $request): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($request->has($f->name)) {
                $out[$f->name] = $request->input($f->name);
            }
        }

        return $out;
    }
}
