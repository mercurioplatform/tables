<?php

namespace Mercurio\Tables\Action\Handlers\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\Handlers\BulkActionHandler;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome\Executed;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome\Queued;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome\Rejected;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Action\Helpers\ActionPayloadResolver;
use Mercurio\Tables\Jobs\BulkActionJob;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\ActionProgress;
use Mercurio\Tables\Services\ActionLogWriter;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Унифицированный pipeline bulk-action'ов — общий для HTML
 * ({@see BulkActionHandler::dispatch()}) и
 * JSON-API ({@see BulkActionHandler::applyAsResult()}).
 * Возвращает {@see BulkPipelineOutcome}; адаптеры маппят его в свой
 * surface type (Response / BulkMutateResult).
 *
 * Log-prefix задаётся per-call: `tables.bulk` для HTML preflight'а,
 * `tables.api.bulk` для API preflight'а, `tables.bulk_progress` /
 * `tables.api.bulk_progress` для queued-path.
 */
class BulkActionPipeline
{
    public function __construct(
        private ActionAuthorizer $authorizer,
        private ActionPayloadResolver $payloads,
    ) {}

    public function preflightLookup(
        ListResource $resource,
        string $name,
        string $logKeyPrefix,
    ): BulkAction|Rejected {
        $action = $this->authorizer->findBulkAction($resource, $name);
        if ($action !== null) {
            return $action;
        }

        Log::warning($logKeyPrefix.'.'.BulkRejectReason::UnknownAction->logSuffix(), [
            'resource' => $resource::class,
            'action' => $name,
        ]);

        return new Rejected(
            reason: BulkRejectReason::UnknownAction,
            message: "Неизвестное действие: {$name}",
            details: ['action' => $name],
        );
    }

    public function preflightCapability(
        ListResource $resource,
        BulkAction $action,
        string $name,
        string $logKeyPrefix,
    ): ?Rejected {
        if ($resource->resolveSource()->capabilities()->mutate) {
            return null;
        }

        Log::warning($logKeyPrefix.'.'.BulkRejectReason::MutateDenied->logSuffix(), [
            'resource' => $resource::class,
            'action' => $name,
        ]);

        return new Rejected(
            reason: BulkRejectReason::MutateDenied,
            message: (string) __('tables::shell.mutate_denied'),
            details: ['capability' => 'mutate'],
        );
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function preflightIds(
        array $ids,
        BulkAction $action,
        string $name,
        string $resourceClass,
        string $logKeyPrefix,
    ): ?Rejected {
        if ($ids !== []) {
            return null;
        }

        Log::warning($logKeyPrefix.'.'.BulkRejectReason::EmptyIds->logSuffix(), [
            'resource' => $resourceClass,
            'action' => $name,
            'kind' => $action->getKind(),
        ]);

        return new Rejected(
            reason: BulkRejectReason::EmptyIds,
            message: 'Не выбрано ни одного объекта.',
            details: ['reason' => 'empty_ids'],
        );
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function preflightAuthz(
        ListResource $resource,
        BulkAction $action,
        array $ids,
        string $name,
        string $logKeyPrefix,
        bool $logForbiddenAlways,
    ): ?Rejected {
        if (! $action->hasPolicy() && $action->getAbility() === null) {
            return null;
        }

        $resourceClass = $resource::class;
        $kind = $action->getKind();
        $probe = $resource->resolveSource()->find($ids[0]);

        if ($probe === null) {
            Log::warning($logKeyPrefix.'.'.BulkRejectReason::ProbeMissing->logSuffix(), [
                'resource' => $resourceClass,
                'action' => $name,
                'kind' => $kind,
                'probe_id' => $ids[0],
            ]);

            return new Rejected(
                reason: BulkRejectReason::ProbeMissing,
                message: 'Запись не найдена.',
                details: ['probe_id' => $ids[0]],
            );
        }

        if ($this->authorizer->authorizeAction($action, $probe, 'bulk', $resource)) {
            return null;
        }

        if ($logForbiddenAlways || ! $action->hasPolicy()) {
            Log::warning($logKeyPrefix.'.'.BulkRejectReason::Forbidden->logSuffix(), [
                'resource' => $resourceClass,
                'action' => $name,
                'kind' => $kind,
                'ability' => $action->getAbility(),
            ]);
        }

        return new Rejected(
            reason: BulkRejectReason::Forbidden,
            message: 'Action запрещён политикой.',
            details: ['action' => $name, 'ability' => $action->getAbility()],
        );
    }

    /**
     * @param  array<string, mixed>  $payloadOverride
     * @return array<string, mixed>
     */
    public function resolvePayload(
        BulkAction $action,
        Request $request,
        string $resourceClass,
        array $payloadOverride,
        bool $isForm,
    ): array {
        if ($isForm) {
            $formRequestClass = $action->getFormRequest();
            if ($formRequestClass !== null) {
                /** @var FormRequest $formRequest */
                $formRequest = app($formRequestClass);

                return $formRequest->validated();
            }

            return $this->payloads->resolveSchemaPayload($action, $request, $resourceClass)['payload'];
        }

        return $payloadOverride !== [] ? $payloadOverride : $action->getPayload();
    }

    public function guardHandlerConfigured(
        BulkAction $action,
        string $name,
        string $resourceClass,
        string $logKeyPrefix,
    ): ?Rejected {
        if ($action->hasCallback() || $action->getHandler() !== null) {
            return null;
        }

        Log::warning($logKeyPrefix.'.'.BulkRejectReason::NoHandlerConfigured->logSuffix(), [
            'resource' => $resourceClass,
            'action' => $name,
        ]);

        return new Rejected(
            reason: BulkRejectReason::NoHandlerConfigured,
            message: 'Действие не настроено.',
            details: ['action' => $name],
        );
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        Request $request,
        ListResource $resource,
        BulkAction $action,
        string $name,
        array $ids,
        array $payload,
        string $logKeyPrefix,
        bool $unifyCallbackThrewKey,
    ): Executed|Rejected {
        $resourceClass = $resource::class;
        $kind = $action->getKind();
        $callback = $action->getCallback();
        $handlerClass = $action->getHandler();
        $mode = $action->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');

        $undoSnapshot = $this->captureUndo($action, $resource, $name, $ids, $payload);

        if ($mode === 'none') {
            return new Executed(
                result: null,
                undoLogId: null,
                ids: $ids,
                payload: $payload,
            );
        }

        $result = null;
        try {
            if ($mode === 'callback') {
                $result = $callback($ids, $payload, $this->authorizer->currentTableActor($resource));
            } else {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($ids, $payload);
            }
        } catch (Throwable $e) {
            $this->logExecutionFailure(
                $e,
                resourceClass: $resourceClass,
                name: $name,
                kind: $kind,
                mode: $mode,
                handlerClass: $handlerClass,
                logKeyPrefix: $logKeyPrefix,
                unify: $unifyCallbackThrewKey,
            );

            return new Rejected(
                reason: BulkRejectReason::CallbackThrew,
                message: 'Выполнение действия завершилось с ошибкой.',
                details: ['action' => $name, 'exception' => $e::class],
                exception: $e,
            );
        }

        $undoLogId = null;
        if ($result instanceof ActionResult) {
            $undoLogId = ActionLogWriter::write(
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

        return new Executed(
            result: $result instanceof ActionResult ? $result : null,
            undoLogId: $undoLogId,
            ids: $ids,
            payload: $payload,
        );
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $payload
     */
    public function dispatchQueued(
        ListResource $resource,
        BulkAction $action,
        string $name,
        array $ids,
        array $payload,
        string $queuedLogPrefix,
    ): Queued|Rejected {
        $resourceClass = $resource::class;
        $handlerClass = $action->getHandler();

        if ($handlerClass === null) {
            Log::error($queuedLogPrefix.'.'.BulkRejectReason::NoHandlerConfigured->logSuffix(), [
                'resource' => $resourceClass,
                'action' => $name,
            ]);

            return new Rejected(
                reason: BulkRejectReason::NoHandlerConfigured,
                message: 'Действие декларировало ::queue(), но не имеет handler-класса.',
                details: ['action' => $name],
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

        return new Queued(progressId: $progressId);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $payload
     */
    private function captureUndo(
        BulkAction $action,
        ListResource $resource,
        string $name,
        array $ids,
        array $payload,
    ): mixed {
        if (! $action->isUndoable()) {
            return null;
        }

        try {
            return ($action->getCaptureCallback())($ids, $payload, $resource);
        } catch (Throwable $e) {
            Log::warning('tables.action.undo.capture_threw', [
                'resource' => $resource::class,
                'action' => $name,
                'kind' => 'bulk',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function logExecutionFailure(
        Throwable $e,
        string $resourceClass,
        string $name,
        string $kind,
        string $mode,
        ?string $handlerClass,
        string $logKeyPrefix,
        bool $unify,
    ): void {
        if ($unify) {
            Log::error($logKeyPrefix.'.'.BulkRejectReason::CallbackThrew->logSuffix(), [
                'resource' => $resourceClass,
                'action' => $name,
                'kind' => $kind,
                'mode' => $mode,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($mode === 'callback') {
            Log::error($logKeyPrefix.'.'.BulkRejectReason::CallbackThrew->logSuffix(), [
                'resource' => $resourceClass,
                'action' => $name,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::error($logKeyPrefix.'.handler_threw', [
            'resource' => $resourceClass,
            'action' => $name,
            'kind' => $kind,
            'handler' => $handlerClass,
            'error' => $e->getMessage(),
        ]);
    }
}
