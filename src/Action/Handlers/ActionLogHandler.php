<?php

namespace Mercurio\Tables\Action\Handlers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Action\Helpers\ActionResponseBuilder;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\ActionLog;
use Mercurio\Tables\Services\ActionLogWriter;
use Mercurio\Tables\Source\Page;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::actionLog()}.
 */
class ActionLogHandler
{
    public function __construct(
        private ActionResponseBuilder $responses,
        private ActionAuthorizer $authorizer,
    ) {}

    public function read(Request $request, ListResource $resource, ?string $routeBaseName): Response
    {
        $resourceClass = $resource::class;

        if (! $resource->actionHistoryEnabled()) {
            Log::warning('tables.action_log.disabled', [
                'resource' => $resourceClass,
            ]);
            abort(404);
        }

        $perPage = (int) config('tables.action_log.per_page', 25);
        $window = (int) config('tables.action_log.recent_limit', 200);
        $page = max(1, (int) $request->input('page', 1));

        $rows = ActionLog::query()
            ->forResource($resource->key())
            ->orderByDesc('id')
            ->limit($window)
            ->get();

        $undoneIds = $this->loadUndoneOriginIds($rows->pluck('id')->all());
        $rows->each(function (ActionLog $row) use ($undoneIds) {
            $row->setAttribute('already_undone', isset($undoneIds[$row->id]));
        });

        $items = $rows->forPage($page, $perPage)->values()->all();
        $total = $rows->count();

        $paginator = new Page(
            rows: $items,
            total: $total,
            page: $page,
            perPage: $perPage,
        );

        Log::debug('tables.action_log.page_built', [
            'resource' => $resource->key(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);

        return response()->view('tables::action-log', [
            'resource' => $resource,
            'paginator' => $paginator,
            'window' => $window,
        ]);
    }

    public function undo(Request $request, ListResource $resource, int $logId): Response
    {
        $resourceClass = $resource::class;

        if (! $resource->actionHistoryEnabled()) {
            Log::warning('tables.action_log.disabled', [
                'resource' => $resourceClass,
            ]);
            abort(404);
        }

        if (! $resource->resolveSource()->capabilities()->mutate) {
            Log::warning('tables.action_log.undo_mutate_denied', [
                'resource' => $resourceClass,
                'log_id' => $logId,
            ]);

            return $this->authorizer->undoError($request, (string) __('tables::shell.mutate_denied'), 422);
        }

        /** @var ?ActionLog $row */
        $row = ActionLog::query()->whereKey($logId)->first();
        if ($row === null) {
            abort(404);
        }

        if ($row->resource_key !== $resource->key()) {
            Log::warning('tables.action_log.undo.scope_mismatch', [
                'resource' => $resourceClass,
                'log_id' => $logId,
                'log_resource' => $row->resource_key,
            ]);
            abort(404);
        }

        if (isset($row->payload_json['undo_of'])) {
            Log::warning('tables.action_log.undo.chain_attempt', [
                'resource' => $resourceClass,
                'log_id' => $logId,
            ]);

            return $this->authorizer->undoError($request, 'Запись является откатом — повторный откат не поддерживается.', 422);
        }

        $undo = $row->result_json['undo'] ?? null;
        $snapshot = is_array($undo) ? ($undo['snapshot'] ?? null) : null;
        if (! is_array($snapshot) || $snapshot === []) {
            return $this->authorizer->undoError($request, 'Снимок отката отсутствует или повреждён.', 422);
        }

        $windowMinutes = (int) config('tables.action_log.undo_window_minutes', 60);
        if ($row->created_at !== null && $row->created_at->lt(now()->subMinutes($windowMinutes))) {
            Log::warning('tables.action_log.undo.window_expired', [
                'resource' => $resourceClass,
                'log_id' => $logId,
                'window' => $windowMinutes,
            ]);

            return $this->authorizer->undoError($request, "Окно отката истекло ({$windowMinutes} мин).", 422);
        }

        if ($this->hasExistingUndo($logId)) {
            return $this->authorizer->undoError($request, 'Откат уже выполнен ранее.', 422);
        }

        $kind = $row->kind === 'row' ? 'row' : 'bulk';
        $actionDecl = $kind === 'bulk'
            ? $this->authorizer->findBulkAction($resource, $row->action_name)
            : $this->authorizer->findRowAction($resource, $row->action_name);

        if ($actionDecl === null || ! $actionDecl->isUndoable()) {
            Log::warning('tables.action_log.undo.action_lost', [
                'resource' => $resourceClass,
                'log_id' => $logId,
                'action_name' => $row->action_name,
                'kind' => $kind,
            ]);

            return $this->authorizer->undoError($request, 'Действие больше не поддерживает откат.', 422);
        }

        $ids = array_keys($snapshot);

        if ($actionDecl->hasPolicy() || $actionDecl->getAbility() !== null) {
            $probeId = $ids[0] ?? null;
            $probe = $probeId !== null ? $resource->resolveSource()->find($probeId) : null;
            if ($probe === null || ! $this->authorizer->authorizeAction($actionDecl, $probe, $kind, $resource)) {
                abort(403);
            }
        }

        $reverseCallback = $actionDecl->getReverseCallback();
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        try {
            $reverseResult = $reverseCallback($ids, $snapshot, $this->authorizer->currentTableActor($resource));
        } catch (Throwable $e) {
            Log::error('tables.action_log.undo.reverse_threw', [
                'resource' => $resourceClass,
                'log_id' => $logId,
                'action_name' => $row->action_name,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return $this->authorizer->undoError($request, 'Откат не выполнен. См. логи.', 500);
        }

        if (! $reverseResult instanceof ActionResult) {
            Log::error('tables.action_log.undo.reverse_returned_invalid', [
                'resource' => $resourceClass,
                'log_id' => $logId,
                'kind' => $kind,
                'returned' => is_object($reverseResult) ? $reverseResult::class : gettype($reverseResult),
            ]);

            return $this->authorizer->undoError($request, 'Откат вернул неверный результат.', 500);
        }

        ActionLogWriter::write(
            resourceKey: $resource->key(),
            actionName: $row->action_name,
            kind: $kind,
            actorId: $this->authorizer->resolveAuditActorId($resource),
            ids: $ids,
            payload: [],
            result: $reverseResult,
            undoSnapshot: null,
            undoOfLogId: $logId,
        );

        $flashResult = new ActionResult(
            affected: $reverseResult->affected,
            missing: $reverseResult->missing,
            message: $reverseResult->message ?? 'Откат выполнен.',
        );

        return $this->responses->flashFromActionResult(
            result: $flashResult,
            action: $actionDecl,
            isXhr: $isXhr,
            reload: true,
            resourceClass: $resourceClass,
        );
    }

    private function hasExistingUndo(int $logId): bool
    {
        $driver = ActionLog::query()->getQuery()->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return ActionLog::query()
                ->whereRaw("json_extract(payload_json, '$.undo_of') = ?", [$logId])
                ->exists();
        }

        return ActionLog::query()
            ->where('payload_json->undo_of', $logId)
            ->exists();
    }

    /**
     * @param  array<int, int>  $logIds
     * @return array<int, true>
     */
    private function loadUndoneOriginIds(array $logIds): array
    {
        if ($logIds === []) {
            return [];
        }

        $driver = ActionLog::query()->getQuery()->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $placeholders = implode(',', array_fill(0, count($logIds), '?'));
            $rows = ActionLog::query()
                ->whereRaw("json_extract(payload_json, '$.undo_of') IN ({$placeholders})", $logIds)
                ->selectRaw("json_extract(payload_json, '$.undo_of') as origin_id")
                ->pluck('origin_id')
                ->all();
        } else {
            $rows = ActionLog::query()
                ->whereIn('payload_json->undo_of', $logIds)
                ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.undo_of')) as origin_id")
                ->pluck('origin_id')
                ->all();
        }

        $out = [];
        foreach ($rows as $oid) {
            if ($oid !== null) {
                $out[(int) $oid] = true;
            }
        }

        return $out;
    }
}
