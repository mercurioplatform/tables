<?php

namespace Mercurio\Tables\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Api\Mutate\BulkMutateResult;
use Mercurio\Tables\Api\Mutate\CellMutateResult;
use Mercurio\Tables\Api\Mutate\MutateResult;
use Mercurio\Tables\Api\Mutate\RowMutateResult;
use Mercurio\Tables\Http\Controllers\JsonApiMutateController;
use Mercurio\Tables\ListResource;

/**
 * Сериализация {@see MutateResult} в JSON-envelope.
 *
 * Возвращает `array<string, mixed>` — внешний контроллер
 * ({@see JsonApiMutateController}) оборачивает
 * это в `JsonResponse` со статусом 200 (sync) или 202 (queued bulk).
 *
 * Per-op envelope:
 *
 * - **cell** → `{data: {id, row}}` (+ `undo` если запрошен и есть log_id; но
 *   cell-edit пока не undoable, поэтому undo блок никогда не появляется);
 * - **row sync** → `{data: {id, affected, message?, payload?}}` (+ `undo`);
 * - **bulk sync** → `{data: {affected, denied, missing, skipped, affected_ids}}` (+ `undo`);
 * - **bulk queued** → `{data: {status:"queued", progress_id, progress_url, total, action_label}}`.
 */
final class MutateRenderer
{
    /**
     * @return array<string, mixed>
     */
    public function render(
        MutateResult $result,
        ParsedMutate $parsed,
        ListResource $resource,
        Request $request,
    ): array {
        $envelope = match (true) {
            $result instanceof CellMutateResult => $this->renderCell($result, $parsed),
            $result instanceof RowMutateResult => $this->renderRow($result, $parsed),
            $result instanceof BulkMutateResult => $this->renderBulk($result, $parsed, $resource),
            default => ['data' => []],
        };

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderCell(CellMutateResult $result, ParsedMutate $parsed): array
    {
        $envelope = [
            'data' => [
                'id' => $result->id,
                'row' => $result->freshRow,
            ],
        ];

        $this->maybeAttachUndo($envelope, $result->undoLogId, $parsed, kind: 'cell');

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderRow(RowMutateResult $result, ParsedMutate $parsed): array
    {
        $data = [
            'id' => $result->id,
            'affected' => $result->actionResult->affected,
        ];
        if ($result->actionResult->message !== null && $result->actionResult->message !== '') {
            $data['message'] = $result->actionResult->message;
        }

        $envelope = ['data' => $data];
        $this->maybeAttachUndo($envelope, $result->undoLogId, $parsed, kind: 'row');

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderBulk(BulkMutateResult $result, ParsedMutate $parsed, ListResource $resource): array
    {
        if ($result->progressId !== null) {
            return [
                'data' => [
                    'status' => 'queued',
                    'progress_id' => $result->progressId,
                    'progress_url' => $this->resolveProgressUrl($resource, $result->progressId),
                    'total' => $parsed->ids !== null ? count($parsed->ids) : 0,
                    'action_label' => $this->resolveActionLabel($resource, $parsed->action),
                ],
            ];
        }

        $envelope = [
            'data' => [
                'affected' => $result->affected,
                'denied' => $result->denied,
                'missing' => $result->missing,
                'skipped' => $result->skipped,
                'affected_ids' => $result->affectedIds,
            ],
        ];

        $this->maybeAttachUndo($envelope, $result->undoLogId, $parsed, kind: 'bulk');

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function maybeAttachUndo(array &$envelope, ?int $undoLogId, ParsedMutate $parsed, string $kind): void
    {
        if ($undoLogId === null || ! $parsed->wantsInclude('undoToken')) {
            return;
        }

        $block = [
            'token' => $undoLogId,
            'kind' => $kind,
            'action' => $kind === 'cell' ? 'cell-update' : ($parsed->action ?? ''),
        ];
        if ($kind === 'cell' && $parsed->field !== null) {
            $block['field'] = $parsed->field;
        }

        $ttl = (int) config('tables.action_log.undo_ttl_minutes', 0);
        if ($ttl > 0) {
            $block['expires_at'] = now()->addMinutes($ttl)->toIso8601String();
        }

        $envelope['undo'] = $block;

    }

    private function resolveProgressUrl(ListResource $resource, string $progressId): ?string
    {
        $base = $resource->routeBaseName();
        if ($base === null || $base === '') {
            return null;
        }

        $name = $base.'.action_progress';
        if (! Route::has($name)) {
            return null;
        }

        return route($name, ['progress' => $progressId]);
    }

    private function resolveActionLabel(ListResource $resource, ?string $action): ?string
    {
        if ($action === null || $action === '') {
            return null;
        }

        foreach ($resource->bulkActions() as $bulk) {
            if ($bulk instanceof BulkAction && $bulk->name === $action) {
                return $bulk->label;
            }
        }

        return null;
    }
}
