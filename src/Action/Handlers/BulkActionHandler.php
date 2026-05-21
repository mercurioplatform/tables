<?php

namespace Mercurio\Tables\Action\Handlers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\Handlers\Support\BulkActionPipeline;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome\Executed;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome\Queued;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome\Rejected;
use Mercurio\Tables\Action\Handlers\Support\BulkRejectReason;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Action\Helpers\ActionPayloadResolver;
use Mercurio\Tables\Action\Helpers\ActionResponseBuilder;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\Mutate\BulkMutateResult;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\ActionProgress;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::bulkAction()}.
 *
 * Бизнес-логика bulk-action'ов живёт в {@see BulkActionPipeline}; этот класс
 * выступает тонким адаптером pipeline-outcome → surface type (HTML Response
 * vs JSON-API {@see BulkMutateResult}).
 */
class BulkActionHandler
{
    private const LOG_PREFIX_HTML = 'tables.bulk';

    private const LOG_PREFIX_API = 'tables.api.bulk';

    private const LOG_PREFIX_QUEUED_HTML = 'tables.bulk_progress';

    private const LOG_PREFIX_QUEUED_API = 'tables.api.bulk_progress';

    public function __construct(
        private ActionResponseBuilder $responses,
        private ActionAuthorizer $authorizer,
        private ActionPayloadResolver $payloads,
        private BulkActionPipeline $pipeline,
    ) {}

    public function dispatch(
        Request $request,
        ListResource $resource,
        ?string $bulkRequestClass,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $name = (string) $request->input('action', '');

        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        $action = $this->pipeline->preflightLookup($resource, $name, self::LOG_PREFIX_HTML);
        if ($action instanceof Rejected) {
            return $this->authorizer->bulkActionError(
                $isXhr,
                $action->message,
                $action->reason->apiErrorCode()->httpStatus(),
            );
        }

        $capabilityReject = $this->pipeline->preflightCapability($resource, $action, $name, self::LOG_PREFIX_HTML);
        if ($capabilityReject !== null) {
            return $this->authorizer->bulkActionError(
                $isXhr,
                $capabilityReject->message,
                $capabilityReject->reason->apiErrorCode()->httpStatus(),
            );
        }

        $kind = $action->getKind();
        $isForm = $kind === 'form';

        if (! $isForm && $bulkRequestClass !== null) {
            $request = app($bulkRequestClass);
        }

        $ids = $this->payloads->extractBulkIds($request);

        $idsReject = $this->pipeline->preflightIds($ids, $action, $name, $resourceClass, self::LOG_PREFIX_HTML);
        if ($idsReject !== null) {
            return $this->authorizer->bulkActionError(
                $isXhr,
                $idsReject->message,
                $idsReject->reason->apiErrorCode()->httpStatus(),
            );
        }

        $authzReject = $this->pipeline->preflightAuthz(
            resource: $resource,
            action: $action,
            ids: $ids,
            name: $name,
            logKeyPrefix: self::LOG_PREFIX_HTML,
            logForbiddenAlways: false,
        );
        if ($authzReject !== null) {
            if ($authzReject->reason === BulkRejectReason::Forbidden) {
                abort(403);
            }

            return $this->authorizer->bulkActionError(
                $isXhr,
                $authzReject->message,
                $authzReject->reason->apiErrorCode()->httpStatus(),
            );
        }

        $payload = $this->pipeline->resolvePayload(
            action: $action,
            request: $request,
            resourceClass: $resourceClass,
            payloadOverride: [],
            isForm: $isForm,
        );

        if (
            (bool) config('tables.bulk_progress.enabled', true)
            && $action->isQueued()
            && $action->shouldQueueFor(count($ids))
        ) {
            if (! $isXhr) {
                Log::warning(self::LOG_PREFIX_QUEUED_HTML.'.non_xhr_submit', [
                    'resource' => $resourceClass,
                    'action' => $name,
                ]);

                return $this->authorizer->bulkActionError(
                    $isXhr,
                    'Async-действие требует XHR submit. Перезагрузите страницу и попробуйте снова.',
                    422,
                );
            }

            $queuedOutcome = $this->pipeline->dispatchQueued(
                resource: $resource,
                action: $action,
                name: $name,
                ids: $ids,
                payload: $payload,
                queuedLogPrefix: self::LOG_PREFIX_QUEUED_HTML,
            );

            if ($queuedOutcome instanceof Rejected) {
                return $this->authorizer->bulkActionError(
                    $isXhr,
                    $queuedOutcome->message,
                    $queuedOutcome->reason->apiErrorCode()->httpStatus(),
                );
            }

            $base = $resource->routeBaseName() ?? $this->authorizer->deriveBaseRouteName($routeBaseName);
            $progressUrl = $base !== '' && Route::has($base.'.action_progress')
                ? route($base.'.action_progress', ['progress' => $queuedOutcome->progressId])
                : null;
            $indexUrl = $base !== '' && Route::has($base.'.index')
                ? route($base.'.index')
                : null;

            return response()->json([
                'status' => 'queued',
                'progress_id' => $queuedOutcome->progressId,
                'progress_url' => $progressUrl,
                'index_url' => $indexUrl,
                'total' => count($ids),
                'action_label' => $action->label,
                'message' => "Действие «{$action->label}» запущено в фоне (".count($ids).' объектов).',
            ], 202);
        }

        $outcome = $this->pipeline->execute(
            request: $request,
            resource: $resource,
            action: $action,
            name: $name,
            ids: $ids,
            payload: $payload,
            logKeyPrefix: self::LOG_PREFIX_HTML,
            unifyCallbackThrewKey: false,
        );

        if ($outcome instanceof Rejected) {
            $exception = $outcome->exception ?? new \RuntimeException($outcome->message);

            return $this->responses->flashFromException($exception, $action, $isXhr, $resourceClass);
        }

        $reload = $isForm ? $action->shouldReloadAfterSubmit() : true;

        return $this->responses->flashFromActionResult(
            result: $outcome->result ?? new ActionResult(affected: 0),
            action: $action,
            isXhr: $isXhr,
            reload: $reload,
            resourceClass: $resourceClass,
        );
    }

    /**
     * Pure-mutate primitive: применяет bulk-action и возвращает {@see BulkMutateResult}.
     *
     * **Queued-path не имеет XHR-guard** — это HTML-specific concern, остающийся
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

        $bulk = $this->pipeline->preflightLookup($resource, $action, self::LOG_PREFIX_API);
        if ($bulk instanceof Rejected) {
            throw $this->buildApiException($bulk);
        }

        $capabilityReject = $this->pipeline->preflightCapability($resource, $bulk, $action, self::LOG_PREFIX_API);
        if ($capabilityReject !== null) {
            throw $this->buildApiException($capabilityReject);
        }

        $idsReject = $this->pipeline->preflightIds($ids, $bulk, $action, $resourceClass, self::LOG_PREFIX_API);
        if ($idsReject !== null) {
            throw $this->buildApiException($idsReject);
        }

        $authzReject = $this->pipeline->preflightAuthz(
            resource: $resource,
            action: $bulk,
            ids: $ids,
            name: $action,
            logKeyPrefix: self::LOG_PREFIX_API,
            logForbiddenAlways: true,
        );
        if ($authzReject !== null) {
            throw $this->buildApiException($authzReject);
        }

        $kind = $bulk->getKind();
        $isForm = $kind === 'form';

        $payload = $this->pipeline->resolvePayload(
            action: $bulk,
            request: $request,
            resourceClass: $resourceClass,
            payloadOverride: $payloadOverride,
            isForm: $isForm,
        );

        if (
            (bool) config('tables.bulk_progress.enabled', true)
            && $bulk->isQueued()
            && $bulk->shouldQueueFor(count($ids))
        ) {
            $queuedOutcome = $this->pipeline->dispatchQueued(
                resource: $resource,
                action: $bulk,
                name: $action,
                ids: $ids,
                payload: $payload,
                queuedLogPrefix: self::LOG_PREFIX_QUEUED_API,
            );

            if ($queuedOutcome instanceof Rejected) {
                throw $this->buildApiException($queuedOutcome);
            }

            return new BulkMutateResult(
                affected: 0,
                missing: 0,
                denied: 0,
                skipped: 0,
                progressId: $queuedOutcome->progressId,
                affectedIds: [],
                undoLogId: null,
            );
        }

        $noHandlerReject = $this->pipeline->guardHandlerConfigured(
            $bulk,
            $action,
            $resourceClass,
            self::LOG_PREFIX_API,
        );
        if ($noHandlerReject !== null) {
            throw $this->buildApiException($noHandlerReject);
        }

        $outcome = $this->pipeline->execute(
            request: $request,
            resource: $resource,
            action: $bulk,
            name: $action,
            ids: $ids,
            payload: $payload,
            logKeyPrefix: self::LOG_PREFIX_API,
            unifyCallbackThrewKey: true,
        );

        if ($outcome instanceof Rejected) {
            throw new ApiValidationException(
                ApiErrorCode::MutationFailed,
                'Выполнение действия завершилось с ошибкой.',
                $outcome->details,
            );
        }

        /** @var Executed $outcome */
        $counts = ['affected' => 0, 'missing' => 0, 'denied' => 0, 'skipped' => 0];
        if ($outcome->result instanceof ActionResult) {
            $counts['affected'] = $outcome->result->affected;
            $counts['missing'] = $outcome->result->missing;
            $counts['denied'] = $outcome->result->denied;
            $counts['skipped'] = $outcome->result->skipped;
        }

        return new BulkMutateResult(
            affected: $counts['affected'],
            missing: $counts['missing'],
            denied: $counts['denied'],
            skipped: $counts['skipped'],
            progressId: null,
            affectedIds: [],
            undoLogId: $outcome->undoLogId,
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

    private function buildApiException(Rejected $rejected): ApiValidationException
    {
        return new ApiValidationException(
            $rejected->reason->apiErrorCode(),
            $rejected->message,
            $rejected->details,
        );
    }
}
