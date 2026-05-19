<?php

namespace Mercurio\Tables\Action\Handlers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Action\Helpers\ActionPayloadResolver;
use Mercurio\Tables\Action\Helpers\ActionResponseBuilder;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\Mutate\RowMutateResult;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Services\ActionLogWriter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::rowAction()}.
 */
class RowActionHandler
{
    public function __construct(
        private ActionResponseBuilder $responses,
        private ActionAuthorizer $authorizer,
        private ActionPayloadResolver $payloads,
    ) {}

    public function dispatch(
        Request $request,
        ListResource $resource,
        $id,
        string $action,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $rowAction = $this->authorizer->findRowAction($resource, $action);
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        if ($rowAction === null) {
            Log::warning('tables.rowaction.unknown', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            return $this->authorizer->rowActionError($request, $isXhr, "Неизвестное действие: {$action}", 404);
        }

        $source = $resource->resolveSource();
        if (! $source->capabilities()->mutate) {
            Log::warning('tables.rowaction.mutate_denied', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);

            return $this->authorizer->rowActionError($request, $isXhr, (string) __('tables::shell.mutate_denied'), 422);
        }

        $model = $source->find($id);
        if ($model === null) {
            Log::warning('tables.rowaction.missing', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);

            return $this->authorizer->rowActionError($request, $isXhr, 'Запись не найдена.', 404);
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.rowaction.hidden', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);

            return $this->authorizer->rowActionError($request, $isXhr, 'Действие недоступно.', 403);
        }

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizer->authorizeAction($rowAction, $model, 'row', $resource)) {
                if (! $rowAction->hasPolicy()) {
                    Log::warning('tables.rowaction.forbidden', [
                        'resource' => $resourceClass,
                        'action' => $action,
                        'id' => $id,
                        'ability' => $rowAction->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $resolved = $this->payloads->resolveRowActionPayload($request, $rowAction, $resourceClass);
        $payload = $resolved['payload'];
        $validationSource = $resolved['source'];

        $callback = $rowAction->getCallback();
        $handlerClass = $rowAction->getHandler();
        $mode = $rowAction->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');

        if ($mode === 'none' && $rowAction->getKind() !== 'link') {
            Log::warning('tables.rowaction.no_handler', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            return $this->authorizer->rowActionError($request, $isXhr, 'Действие не настроено.', 500);
        }

        $undoSnapshot = null;
        if ($rowAction->isUndoable()) {
            try {
                $undoSnapshot = ($rowAction->getCaptureCallback())([$model->getKey()], $payload, $resource);
            } catch (Throwable $e) {
                Log::warning('tables.action.undo.capture_threw', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'kind' => 'row',
                    'error' => $e->getMessage(),
                ]);
                $undoSnapshot = null;
            }
        }

        $result = null;
        try {
            if ($mode === 'callback') {
                $result = $callback($model, $payload, $this->authorizer->currentTableActor($resource));
            } elseif ($mode === 'handler') {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($model, $payload);
            }
        } catch (Throwable $e) {
            $logKey = $mode === 'callback' ? 'tables.rowaction.callback_threw' : 'tables.rowaction.handler_threw';
            Log::error($logKey, [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
                'handler' => $handlerClass,
                'error' => $e->getMessage(),
            ]);

            return $this->responses->flashFromException($e, $rowAction, $isXhr, $resourceClass);
        }

        if ($result instanceof ActionResult && $rowAction->getKind() !== 'link') {
            ActionLogWriter::write(
                resourceKey: $resource->key(),
                actionName: $action,
                kind: 'row',
                actorId: $this->authorizer->resolveAuditActorId($resource),
                ids: [$model->getKey()],
                payload: $payload,
                result: $result,
                undoSnapshot: $undoSnapshot,
            );
        }

        return $this->responses->flashFromActionResult(
            result: $result ?? new ActionResult(affected: 0),
            action: $rowAction,
            isXhr: $isXhr,
            reload: $rowAction->shouldReloadAfterSubmit(),
            resourceClass: $resourceClass,
        );
    }

    /**
     * Pure-mutate primitive: применяет row-action и возвращает {@see RowMutateResult}.
     *
     * Бросает {@see ApiValidationException} с конкретным {@see ApiErrorCode}.
     * Laravel `ValidationException` (от form-payload resolve) пробрасывается
     * наружу — JSON-API-контроллер ловит её отдельным catch'ем.
     *
     * @param  int|string  $id
     */
    public function applyAsResult(Request $request, ListResource $resource, $id, string $action): RowMutateResult
    {
        $resourceClass = $resource::class;
        $rowAction = $this->authorizer->findRowAction($resource, $action);

        if ($rowAction === null) {
            Log::warning('tables.api.row_action.unknown', [
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
            Log::warning('tables.api.row_action.mutate_denied', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::CapabilityUnsupported,
                (string) __('tables::shell.mutate_denied'),
                ['capability' => 'mutate'],
            );
        }

        $model = $source->find($id);
        if ($model === null) {
            Log::warning('tables.api.row_action.missing', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::RecordNotFound,
                'Запись не найдена.',
                ['id' => $id],
            );
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.api.row_action.hidden', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::PolicyDenied,
                'Действие недоступно.',
                ['action' => $action, 'reason' => 'hidden'],
            );
        }

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizer->authorizeAction($rowAction, $model, 'row', $resource)) {
                Log::warning('tables.api.row_action.forbidden', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'id' => $id,
                    'ability' => $rowAction->getAbility(),
                ]);

                throw new ApiValidationException(
                    ApiErrorCode::PolicyDenied,
                    'Action запрещён политикой.',
                    ['action' => $action, 'ability' => $rowAction->getAbility()],
                );
            }
        }

        if ($rowAction->getKind() === 'link') {
            Log::warning('tables.api.row_action.link_unsupported', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                'link-action не поддерживается через JSON API.',
                ['action' => $action, 'kind' => 'link'],
            );
        }

        $resolved = $this->payloads->resolveRowActionPayload($request, $rowAction, $resourceClass);
        $payload = $resolved['payload'];

        $callback = $rowAction->getCallback();
        $handlerClass = $rowAction->getHandler();
        $mode = $rowAction->hasCallback() ? 'callback' : ($handlerClass !== null ? 'handler' : 'none');

        if ($mode === 'none') {
            Log::warning('tables.api.row_action.no_handler', [
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
        if ($rowAction->isUndoable()) {
            try {
                $undoSnapshot = ($rowAction->getCaptureCallback())([$model->getKey()], $payload, $resource);
            } catch (Throwable $e) {
                Log::warning('tables.action.undo.capture_threw', [
                    'resource' => $resourceClass,
                    'action' => $action,
                    'kind' => 'row',
                    'error' => $e->getMessage(),
                ]);
                $undoSnapshot = null;
            }
        }

        $result = null;
        try {
            if ($mode === 'callback') {
                $result = $callback($model, $payload, $this->authorizer->currentTableActor($resource));
            } elseif ($mode === 'handler') {
                /** @var Action $handler */
                $handler = app($handlerClass);
                $result = $handler->execute($model, $payload);
            }
        } catch (Throwable $e) {
            Log::error('tables.api.row_action.callback_threw', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
                'handler' => $handlerClass,
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
        $actionResult = $result instanceof ActionResult ? $result : new ActionResult(affected: 0);
        if ($result instanceof ActionResult) {
            $undoLogId = ActionLogWriter::write(
                resourceKey: $resource->key(),
                actionName: $action,
                kind: 'row',
                actorId: $this->authorizer->resolveAuditActorId($resource),
                ids: [$model->getKey()],
                payload: $payload,
                result: $result,
                undoSnapshot: $undoSnapshot,
            );
        }

        return new RowMutateResult($model->getKey(), $actionResult, $undoLogId);
    }

    public function renderForm(
        Request $request,
        ListResource $resource,
        $id,
        string $action,
        ?string $tableView,
        array $rowActionForms,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $rowAction = $this->authorizer->findRowAction($resource, $action);

        if ($rowAction === null) {
            Log::warning('tables.rowaction.form.unknown', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($rowAction->getKind() !== 'form') {
            abort(404);
        }

        $model = $resource->resolveSource()->find($id);
        if ($model === null) {
            abort(404);
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.rowaction.form.hidden', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);
            abort(403);
        }

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizer->authorizeAction($rowAction, $model, 'row', $resource)) {
                if (! $rowAction->hasPolicy()) {
                    Log::warning('tables.rowaction.form.forbidden', [
                        'resource' => $resourceClass,
                        'action' => $action,
                        'id' => $id,
                        'ability' => $rowAction->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $base = $this->authorizer->deriveBaseRouteName($routeBaseName);
        $submitUrl = route($base.'.row_action', ['id' => $id, 'action' => $action]);

        if ($rowAction->hasSchema()) {
            return response()->view('tables::auto-row-form', [
                'action' => $rowAction,
                'model' => $model,
                'submitUrl' => $submitUrl,
                'schema' => $rowAction->getSchema(),
            ]);
        }

        $view = $rowActionForms[$action] ?? null;
        if ($view === null && is_string($tableView) && $tableView !== '') {
            $view = $tableView.'-row-action-'.$action;
        }

        if ($view === null || ! view()->exists($view)) {
            Log::warning('tables.rowaction.form.view_missing', [
                'resource' => $resourceClass,
                'action' => $action,
                'view' => $view,
            ]);
            abort(404);
        }

        return response()->view($view, [
            'model' => $model,
            'action' => $rowAction,
            'submitUrl' => $submitUrl,
        ]);
    }

    public function renderPreview(
        Request $request,
        ListResource $resource,
        $id,
        string $action,
        ?string $tableView,
        ?string $routeBaseName,
    ): Response {
        $resourceClass = $resource::class;
        $rowAction = $this->authorizer->findRowAction($resource, $action);

        if ($rowAction === null) {
            Log::warning('tables.confirm.preview.unknown', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(404);
        }

        if ($rowAction->getKind() !== 'confirm') {
            Log::warning('tables.confirm.preview.kind_mismatch', [
                'resource' => $resourceClass,
                'action' => $action,
                'kind' => $rowAction->getKind(),
            ]);
            abort(404);
        }

        if (! $rowAction->hasPreview()) {
            Log::warning('tables.confirm.preview.no_callback', [
                'resource' => $resourceClass,
                'action' => $action,
            ]);
            abort(404);
        }

        $model = $resource->resolveSource()->find($id);
        if ($model === null) {
            abort(404);
        }

        if ($rowAction->isHiddenFor($model)) {
            Log::warning('tables.confirm.preview.hidden', [
                'resource' => $resourceClass,
                'action' => $action,
                'id' => $id,
            ]);
            abort(403);
        }

        if ($rowAction->hasPolicy() || $rowAction->getAbility() !== null) {
            if (! $this->authorizer->authorizeAction($rowAction, $model, 'row', $resource)) {
                if (! $rowAction->hasPolicy()) {
                    Log::warning('tables.confirm.preview.forbidden', [
                        'resource' => $resourceClass,
                        'action' => $action,
                        'id' => $id,
                        'ability' => $rowAction->getAbility(),
                    ]);
                }
                abort(403);
            }
        }

        $payload = $this->payloads->resolveRowActionPayload($request, $rowAction, $resourceClass);
        $cb = $rowAction->getPreviewCallback();

        try {
            $result = $cb($model, $payload);
        } catch (Throwable $e) {
            Log::error('tables.confirm.preview.callback_threw', [
                'resource' => $resourceClass,
                'action' => $action,
                'kind' => 'row',
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Не удалось построить превью.');
        }

        $html = $this->payloads->renderActionPreview($result);
        $base = $this->authorizer->deriveBaseRouteName($routeBaseName);
        $submitUrl = route($base.'.row_action', ['id' => $id, 'action' => $action]);

        return response()->view('tables::confirm-preview', [
            'kind' => 'row',
            'action' => $rowAction,
            'model' => $model,
            'submitUrl' => $submitUrl,
            'html' => $html,
        ]);
    }
}
