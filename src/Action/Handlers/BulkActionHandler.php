<?php

namespace Mercurio\Tables\Action\Handlers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Action\Helpers\ActionPayloadResolver;
use Mercurio\Tables\Action\Helpers\ActionResponseBuilder;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\Mutate\BulkMutateResult;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\Jobs\BulkActionJob;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\ActionProgress;
use Mercurio\Tables\Services\ActionLogWriter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::bulkAction()}.
 */
class BulkActionHandler
{
    public function __construct(
        private ActionResponseBuilder $responses,
        private ActionAuthorizer $authorizer,
        private ActionPayloadResolver $payloads,
    ) {}

    public function dispatch(
        Request $request,
        ListResource $resource,
        ?string $bulkRequestClass,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $name = (string) $request->input('action', '');
        $action = $this->authorizer->findBulkAction($resource, $name);

        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        if ($action === null) {
            Log::warning('tables.bulk.unknown_action', [
                'resource' => $resourceClass,
                'action' => $name,
            ]);

            return $this->authorizer->bulkActionError($isXhr, "Неизвестное действие: {$name}", 404);
        }

        $source = $resource->resolveSource();
        if (! $source->capabilities()->mutate) {
            Log::warning('tables.bulk.mutate_denied', [
                'resource' => $resourceClass,
                'action' => $name,
            ]);

            return $this->authorizer->bulkActionError($isXhr, (string) __('tables::shell.mutate_denied'), 422);
        }

        $kind = $action->getKind();
        $isForm = $kind === 'form';

        if (! $isForm && $bulkRequestClass !== null) {
            $request = app($bulkRequestClass);
        }

        $ids = $this->payloads->extractBulkIds($request);

        if ($ids === []) {
            Log::warning('tables.bulk.empty_ids', [
                'resource' => $resourceClass,
                'action' => $name,
                'kind' => $kind,
            ]);

            return $this->authorizer->bulkActionError($isXhr, 'Не выбрано ни одного объекта.', 422);
        }

        if ($action->hasPolicy() || $action->getAbility() !== null) {
            $probe = $resource->resolveSource()->find($ids[0]);
            if ($probe === null) {
                Log::warning('tables.bulk.probe_missing', [
                    'resource' => $resourceClass,
                    'action' => $name,
                    'kind' => $kind,
                    'probe_id' => $ids[0],
                ]);

                return $this->authorizer->bulkActionError($isXhr, 'Запись не найдена.', 404);
            }
            if (! $this->authorizer->authorizeAction($action, $probe, 'bulk', $resource)) {
                if (! $action->hasPolicy()) {
                    Log::warning('tables.bulk.forbidden', [
                        'resource' => $resourceClass,
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
                $resolved = $this->payloads->resolveSchemaPayload($action, $request, $resourceClass);
                $payload = $resolved['payload'];
                $validationSource = $resolved['source'];
            }
        } else {
            $payload = $action->getPayload();
        }

        if (
            (bool) config('tables.bulk_progress.enabled', true)
            && $action->isQueued()
            && $action->shouldQueueFor(count($ids))
        ) {
            return $this->dispatchQueued(
                resource: $resource,
                action: $action,
                name: $name,
                ids: $ids,
                payload: $payload,
                isXhr: $isXhr,
                routeBaseName: $routeBaseName,
            );
        }

        $callback = $action->getCallback();
        $handlerClass = $action->getHandler();
        $mode = $action->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');
        $result = null;

        $undoSnapshot = null;
        if ($action->isUndoable()) {
            $captureCallback = $action->getCaptureCallback();
            try {
                $undoSnapshot = $captureCallback($ids, $payload, $resource);
            } catch (Throwable $e) {
                Log::warning('tables.action.undo.capture_threw', [
                    'resource' => $resourceClass,
                    'action' => $name,
                    'kind' => 'bulk',
                    'error' => $e->getMessage(),
                ]);
                $undoSnapshot = null;
            }
        }

        if ($mode === 'callback') {
            try {
                $result = $callback($ids, $payload, $this->authorizer->currentTableActor($resource));
            } catch (Throwable $e) {
                Log::error('tables.bulk.callback_threw', [
                    'resource' => $resourceClass,
                    'action' => $name,
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ]);

                return $this->responses->flashFromException($e, $action, $isXhr, $resourceClass);
            }
        } elseif ($mode === 'handler') {
            try {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($ids, $payload);
            } catch (Throwable $e) {
                Log::error('tables.bulk.handler_threw', [
                    'resource' => $resourceClass,
                    'action' => $name,
                    'kind' => $kind,
                    'handler' => $handlerClass,
                    'error' => $e->getMessage(),
                ]);

                return $this->responses->flashFromException($e, $action, $isXhr, $resourceClass);
            }
        }

        if ($result instanceof ActionResult) {
            ActionLogWriter::write(
                resourceKey: $resource->key(),
                actionName: $name,
                kind: 'bulk',
                actorId: $this->authorizer->resolveAuditActorId($resource),
                ids: $ids,
                payload: $payload,
                result: $result,
                undoSnapshot: $undoSnapshot,
            );
        }

        $reload = $isForm ? $action->shouldReloadAfterSubmit() : true;

        return $this->responses->flashFromActionResult(
            result: $result ?? new ActionResult(affected: 0),
            action: $action,
            isXhr: $isXhr,
            reload: $reload,
            resourceClass: $resourceClass,
        );
    }

    /**
     * Pure-mutate primitive: применяет bulk-action и возвращает {@see BulkMutateResult}.
     *
     * **Queued-path не имеет XHR-guard** — это HTML-specific concern, оставшийся
     * в {@see self::dispatch()}. JSON-API queued-bulk возвращает 202 без
     * каких-либо partial-заголовков.
     *
     * Бросает {@see ApiValidationException} с конкретным {@see ApiErrorCode}.
     * Laravel `ValidationException` (от FormRequest при form-kind) пробрасывается
     * наружу — JSON-API-контроллер ловит её отдельным catch'ем.
     *
     * @param  array<int, int|string>  $ids
     * @param  array<string, mixed>  $payloadOverride
     */
    public function applyAsResult(
        Request $request,
        ListResource $resource,
        string $action,
        array $ids,
        array $payloadOverride = [],
    ): BulkMutateResult {
        $resourceClass = $resource::class;
        $bulk = $this->authorizer->findBulkAction($resource, $action);

        if ($bulk === null) {
            Log::warning('tables.api.bulk.unknown_action', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::ActionNotFound,
                "Неизвестное действие: {$action}",
                ['action' => $action],
            );
        }

        $source = $resource->resolveSource();
        if (! $source->capabilities()->mutate) {
            Log::warning('tables.api.bulk.mutate_denied', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::CapabilityUnsupported,
                (string) __('tables::shell.mutate_denied'),
                ['capability' => 'mutate'],
            );
        }

        if ($ids === []) {
            Log::warning('tables.api.bulk.empty_ids', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                'Не выбрано ни одного объекта.',
                ['reason' => 'empty_ids'],
            );
        }

        $kind = $bulk->getKind();
        $isForm = $kind === 'form';

        if ($bulk->hasPolicy() || $bulk->getAbility() !== null) {
            $probe = $source->find($ids[0]);
            if ($probe === null) {
                Log::warning('tables.api.bulk.probe_missing', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'kind' => $kind,
                    'probe_id' => $ids[0],
                ]);

                throw new ApiValidationException(
                    ApiErrorCode::RecordNotFound,
                    'Запись не найдена.',
                    ['probe_id' => $ids[0]],
                );
            }
            if (! $this->authorizer->authorizeAction($bulk, $probe, 'bulk', $resource)) {
                Log::warning('tables.api.bulk.forbidden', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'kind' => $kind,
                    'ability' => $bulk->getAbility(),
                ]);

                throw new ApiValidationException(
                    ApiErrorCode::PolicyDenied,
                    'Action запрещён политикой.',
                    ['action' => $action, 'ability' => $bulk->getAbility()],
                );
            }
        }

        $payload = [];
        $formRequestClass = $isForm ? $bulk->getFormRequest() : null;

        if ($isForm) {
            if ($formRequestClass !== null) {
                // Laravel FormRequest hook валидирует автоматически при resolve;
                // на провале ValidationException пробрасывается ВЫШЕ — контроллер
                // ловит и транслирует в 422 с details.errors.
                /** @var FormRequest $formRequest */
                $formRequest = app($formRequestClass);
                $payload = $formRequest->validated();
            } else {
                $resolved = $this->payloads->resolveSchemaPayload($bulk, $request, $resourceClass);
                $payload = $resolved['payload'];
            }
        } else {
            $payload = $payloadOverride !== [] ? $payloadOverride : $bulk->getPayload();
        }

        if (
            (bool) config('tables.bulk_progress.enabled', true)
            && $bulk->isQueued()
            && $bulk->shouldQueueFor(count($ids))
        ) {
            return $this->dispatchQueuedPure(
                resource: $resource,
                action: $bulk,
                name: $action,
                ids: $ids,
                payload: $payload,
            );
        }

        $callback = $bulk->getCallback();
        $handlerClass = $bulk->getHandler();
        $mode = $bulk->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');

        if ($mode === 'none') {
            Log::warning('tables.api.bulk.no_handler', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::MutationFailed,
                'Действие не настроено.',
                ['action' => $action],
            );
        }

        $undoSnapshot = null;
        if ($bulk->isUndoable()) {
            try {
                $undoSnapshot = ($bulk->getCaptureCallback())($ids, $payload, $resource);
            } catch (Throwable $e) {
                Log::warning('tables.action.undo.capture_threw', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'kind' => 'bulk',
                    'error' => $e->getMessage(),
                ]);
                $undoSnapshot = null;
            }
        }

        $result = null;
        try {
            if ($mode === 'callback') {
                $result = $callback($ids, $payload, $this->authorizer->currentTableActor($resource));
            } elseif ($mode === 'handler') {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($ids, $payload);
            }
        } catch (Throwable $e) {
            Log::error('tables.api.bulk.callback_threw', [
                'resource' => $resourceClass,
                'action' => $action,
                'kind' => $kind,
                'mode' => $mode,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw new ApiValidationException(
                ApiErrorCode::MutationFailed,
                'Выполнение действия завершилось с ошибкой.',
                ['action' => $action, 'exception' => $e::class],
            );
        }

        $undoLogId = null;
        $affectedIds = [];
        $counts = ['affected' => 0, 'missing' => 0, 'denied' => 0, 'skipped' => 0];

        if ($result instanceof ActionResult) {
            $counts['affected'] = $result->affected;
            $counts['missing'] = $result->missing;
            $counts['denied'] = $result->denied;
            $counts['skipped'] = $result->skipped;

            $undoLogId = ActionLogWriter::write(
                resourceKey: $resource->key(),
                actionName: $action,
                kind: 'bulk',
                actorId: $this->authorizer->resolveAuditActorId($resource),
                ids: $ids,
                payload: $payload,
                result: $result,
                undoSnapshot: $undoSnapshot,
            );
        }

        return new BulkMutateResult(
            affected: $counts['affected'],
            missing: $counts['missing'],
            denied: $counts['denied'],
            skipped: $counts['skipped'],
            progressId: null,
            affectedIds: $affectedIds,
            undoLogId: $undoLogId,
        );
    }

    /**
     * Pure queued-path для {@see self::applyAsResult()} — без XHR-guard'а.
     *
     * @param  array<int, int|string>  $ids
     * @param  array<string, mixed>  $payload
     */
    private function dispatchQueuedPure(
        ListResource $resource,
        BulkAction $action,
        string $name,
        array $ids,
        array $payload,
    ): BulkMutateResult {
        $resourceClass = $resource::class;
        $handlerClass = $action->getHandler();
        if ($handlerClass === null) {
            Log::error('tables.api.bulk_progress.no_handler', [
                'resource' => $resourceClass,
                'action' => $name,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::MutationFailed,
                'Действие декларировало ::queue(), но не имеет handler-класса.',
                ['action' => $name],
            );
        }

        $progressId = (string) Str::uuid();
        $actorId = $this->authorizer->resolveAuditActorId($resource);

        ActionProgress::create([
            'id' => $progressId,
            'resource_key' => $resource->key(),
            'action_name' => $name,
            'kind' => 'bulk',
            'actor_id' => $actorId,
            'status' => 'pending',
            'total' => count($ids),
            'processed' => 0,
            'affected' => 0,
            'missing' => 0,
            'denied' => 0,
            'skipped' => 0,
            'affected_ids_json' => [],
            'payload_json' => $payload,
        ]);

        $jobClass = (string) config('tables.bulk_progress.job_class', BulkActionJob::class);
        if ($jobClass === '' || ! class_exists($jobClass)) {
            $jobClass = BulkActionJob::class;
        }

        $queueName = $action->getQueueName();

        /** @var BulkActionJob $job */
        $job = new $jobClass(
            resourceClass: $resourceClass,
            actionName: $name,
            ids: $ids,
            payload: $payload,
            actorId: $actorId,
            progressId: $progressId,
        );

        if ($queueName !== null && $queueName !== '') {
            $job->onQueue($queueName);
        }

        dispatch($job);

        return new BulkMutateResult(
            affected: 0,
            missing: 0,
            denied: 0,
            skipped: 0,
            progressId: $progressId,
            affectedIds: [],
            undoLogId: null,
        );
    }

    public function renderForm(
        Request $request,
        ListResource $resource,
        string $action,
        ?string $tableView,
        array $bulkActionForms,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $bulk = $this->authorizer->findBulkAction($resource, $action);

        if ($bulk === null) {
            Log::warning('tables.bulk.form.unknown', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($bulk->getKind() !== 'form') {
            abort(404);
        }

        $ids = $this->payloads->extractBulkIds($request);
        if ($ids === []) {
            Log::warning('tables.bulk.form.empty_ids', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(422, 'Не выбрано ни одного объекта.');
        }

        if ($bulk->hasPolicy() || $bulk->getAbility() !== null) {
            $probe = $resource->resolveSource()->find($ids[0]);
            if ($probe === null) {
                Log::warning('tables.bulk.form.probe_missing', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'probe_id' => $ids[0],
                ]);
                abort(404, 'Запись не найдена.');
            }
            if (! $this->authorizer->authorizeAction($bulk, $probe, 'bulk', $resource)) {
                if (! $bulk->hasPolicy()) {
                    Log::warning('tables.bulk.form.forbidden', [
                        'resource' => $resourceClass,
                        'action' => $action,
                        'ability' => $bulk->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $base = $this->authorizer->deriveBaseRouteName($routeBaseName);
        $submitUrl = route($base.'.bulk_action');

        if ($bulk->hasSchema()) {
            return response()->view('tables::auto-bulk-form', [
                'action' => $bulk,
                'ids' => $ids,
                'idsCount' => count($ids),
                'submitUrl' => $submitUrl,
                'schema' => $bulk->getSchema(),
            ]);
        }

        $view = $bulkActionForms[$action] ?? null;
        if ($view === null && is_string($tableView) && $tableView !== '') {
            $view = $tableView.'-bulk-'.$action;
        }

        if ($view === null || ! view()->exists($view)) {
            Log::warning('tables.bulk.form.view_missing', [
                'resource' => $resourceClass,
                'action' => $action,
                'view' => $view,
            ]);
            abort(404);
        }

        return response()->view($view, [
            'action' => $bulk,
            'ids' => $ids,
            'idsCount' => count($ids),
            'submitUrl' => $submitUrl,
        ]);
    }

    public function renderPreview(
        Request $request,
        ListResource $resource,
        string $action,
        ?string $tableView,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $bulk = $this->authorizer->findBulkAction($resource, $action);

        if ($bulk === null) {
            Log::warning('tables.confirm.preview.unknown', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($bulk->getKind() !== 'confirm') {
            Log::warning('tables.confirm.preview.kind_mismatch', [
                'resource' => $resourceClass,
                'action' => $action,
                'kind' => $bulk->getKind(),
            ]);
            abort(404);
        }

        if (! $bulk->hasPreview()) {
            Log::warning('tables.confirm.preview.no_callback', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(404);
        }

        $ids = $this->payloads->extractBulkIds($request);
        if ($ids === []) {
            Log::warning('tables.confirm.preview.empty_ids', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(422, 'Не выбрано ни одного объекта.');
        }

        if ($bulk->hasPolicy() || $bulk->getAbility() !== null) {
            $probe = $resource->resolveSource()->find($ids[0]);
            if ($probe === null) {
                Log::warning('tables.confirm.preview.probe_missing', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'probe_id' => $ids[0],
                ]);
                abort(404);
            }
            if (! $this->authorizer->authorizeAction($bulk, $probe, 'bulk', $resource)) {
                if (! $bulk->hasPolicy()) {
                    Log::warning('tables.confirm.preview.forbidden', [
                        'resource' => $resourceClass,
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
                'resource' => $resourceClass,
                'action' => $action,
                'kind' => 'bulk',
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Не удалось построить превью.');
        }

        $html = $this->payloads->renderActionPreview($result);
        $base = $this->authorizer->deriveBaseRouteName($routeBaseName);
        $submitUrl = route($base.'.bulk_action');

        return response()->view('tables::confirm-preview', [
            'kind' => 'bulk',
            'action' => $bulk,
            'ids' => $ids,
            'idsCount' => count($ids),
            'submitUrl' => $submitUrl,
            'html' => $html,
        ]);
    }

    public function progress(Request $request, ListResource $resource, string $progress): Response
    {
        $resourceClass = $resource::class;

        /** @var ActionProgress|null $row */
        $row = ActionProgress::query()->find($progress);

        if ($row === null) {
            Log::warning('tables.bulk_progress.read.not_found', [
                'resource' => $resourceClass,
                'progress_id' => $progress,
            ]);
            abort(404);
        }

        $actorId = $this->authorizer->resolveAuditActorId($resource);
        if ($row->actor_id !== $actorId) {
            Log::warning('tables.bulk_progress.read.foreign', [
                'resource' => $resourceClass,
                'progress_id' => $progress,
                'row_actor' => $row->actor_id,
                'request_actor' => $actorId,
            ]);
            abort(403);
        }

        if ($row->resource_key !== $resource->key()) {
            Log::warning('tables.bulk_progress.read.wrong_resource', [
                'resource' => $resourceClass,
                'progress_id' => $progress,
                'row_resource' => $row->resource_key,
            ]);
            abort(404);
        }

        $cap = (int) config('tables.bulk_progress.max_affected_ids_for_cta', 200);

        return response()->json([
            'progress_id' => $row->id,
            'status' => $row->status,
            'total' => $row->total,
            'processed' => $row->processed,
            'affected' => $row->affected,
            'missing' => $row->missing,
            'denied' => $row->denied,
            'skipped' => $row->skipped,
            'error_message' => $row->error_message,
            'started_at' => $row->started_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
            'affected_ids_preview' => array_slice($row->affected_ids_json ?? [], 0, max(0, $cap)),
        ]);
    }

    public function resolveBulkActionUrl(ListResource $resource, ?string $routeBaseName): string
    {
        $base = $resource->routeBaseName() ?? $this->authorizer->deriveBaseRouteName($routeBaseName);
        if ($base === '') {
            return '';
        }

        $routeName = $base.'.bulk_action';
        if (! Route::has($routeName)) {
            return '';
        }

        return route($routeName);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $payload
     */
    private function dispatchQueued(
        ListResource $resource,
        BulkAction $action,
        string $name,
        array $ids,
        array $payload,
        bool $isXhr,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $handlerClass = $action->getHandler();
        if ($handlerClass === null) {
            Log::error('tables.bulk_progress.no_handler', [
                'resource' => $resourceClass,
                'action' => $name,
            ]);

            return $this->authorizer->bulkActionError(
                $isXhr,
                'Действие декларировало ::queue(), но не имеет handler-класса.',
                500,
            );
        }

        if (! $isXhr) {
            Log::warning('tables.bulk_progress.non_xhr_submit', [
                'resource' => $resourceClass,
                'action' => $name,
            ]);

            return $this->authorizer->bulkActionError(
                $isXhr,
                'Async-действие требует XHR submit. Перезагрузите страницу и попробуйте снова.',
                422,
            );
        }

        $progressId = (string) Str::uuid();
        $actorId = $this->authorizer->resolveAuditActorId($resource);

        ActionProgress::create([
            'id' => $progressId,
            'resource_key' => $resource->key(),
            'action_name' => $name,
            'kind' => 'bulk',
            'actor_id' => $actorId,
            'status' => 'pending',
            'total' => count($ids),
            'processed' => 0,
            'affected' => 0,
            'missing' => 0,
            'denied' => 0,
            'skipped' => 0,
            'affected_ids_json' => [],
            'payload_json' => $payload,
        ]);

        $jobClass = (string) config('tables.bulk_progress.job_class', BulkActionJob::class);
        if (! is_string($jobClass) || $jobClass === '' || ! class_exists($jobClass)) {
            $jobClass = BulkActionJob::class;
        }

        $queueName = $action->getQueueName();

        /** @var BulkActionJob $job */
        $job = new $jobClass(
            resourceClass: $resourceClass,
            actionName: $name,
            ids: $ids,
            payload: $payload,
            actorId: $actorId,
            progressId: $progressId,
        );

        if ($queueName !== null && $queueName !== '') {
            $job->onQueue($queueName);
        }

        dispatch($job);

        $base = $resource->routeBaseName() ?? $this->authorizer->deriveBaseRouteName($routeBaseName);
        $progressUrl = $base !== '' && Route::has($base.'.action_progress')
            ? route($base.'.action_progress', ['progress' => $progressId])
            : null;
        $indexUrl = $base !== '' && Route::has($base.'.index')
            ? route($base.'.index')
            : null;

        return response()->json([
            'status' => 'queued',
            'progress_id' => $progressId,
            'progress_url' => $progressUrl,
            'index_url' => $indexUrl,
            'total' => count($ids),
            'action_label' => $action->label,
            'message' => "Действие «{$action->label}» запущено в фоне (".count($ids).' объектов).',
        ], 202);
    }
}
