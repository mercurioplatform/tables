<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Models\ActionLog;
use Throwable;

final class ActionLogWriter
{
    /**
     * @param  array<int, mixed>  $ids  primary keys
     * @param  array<string, mixed>  $payload  validated payload
     * @param  array<string, mixed>|null  $undoSnapshot  per-id snapshot для отката (null — не undoable)
     * @param  ?int  $undoOfLogId  если запись — undo, ID исходной log-записи (записывается в payload_json.undo_of)
     */
    public static function write(
        string $resourceKey,
        string $actionName,
        string $kind,
        ?int $actorId,
        array $ids,
        array $payload,
        ?ActionResult $result,
        ?array $undoSnapshot = null,
        ?int $undoOfLogId = null,
    ): void {
        if (! (bool) config('tables.action_log.enabled', true)) {
            return;
        }

        try {
            ActionLog::create([
                'resource_key' => $resourceKey,
                'action_name' => $actionName,
                'kind' => $kind,
                'actor_id' => $actorId,
                'payload_json' => self::serializePayload(
                    $undoOfLogId !== null
                        ? ['undo_of' => $undoOfLogId] + $payload
                        : $payload,
                    $resourceKey,
                    $actionName,
                ),
                'subjects_json' => self::serializeSubjects($ids),
                'result_json' => self::serializeResult($result, $undoSnapshot, $resourceKey, $actionName),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('tables.action_log.write_failed', [
                'resource' => $resourceKey,
                'action' => $actionName,
                'kind' => $kind,
                'undo_of' => $undoOfLogId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, mixed>
     */
    private static function serializeSubjects(array $ids): array
    {
        $limit = (int) config('tables.action_log.subjects_id_limit', 100);
        $count = count($ids);

        if ($count <= $limit) {
            return [
                'ids' => array_values(array_map(fn ($v) => is_scalar($v) ? $v : (string) $v, $ids)),
                'count' => $count,
            ];
        }

        return ['count' => $count];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function serializePayload(array $payload, string $resourceKey, string $actionName): array
    {
        $max = (int) config('tables.action_log.payload_max_bytes', 16384);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > $max) {
            Log::warning('tables.action_log.payload_truncated', [
                'resource' => $resourceKey,
                'action' => $actionName,
                'size' => $encoded === false ? null : strlen($encoded),
                'limit' => $max,
            ]);

            return ['_truncated' => true, 'size' => $encoded === false ? null : strlen($encoded)];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $undoSnapshot
     * @return array<string, mixed>|null
     */
    private static function serializeResult(?ActionResult $result, ?array $undoSnapshot, string $resourceKey, string $actionName): ?array
    {
        $out = $result === null ? null : $result->counts();
        if ($result !== null && $result->message !== null && $result->message !== '') {
            $out['message'] = $result->message;
        }

        if ($undoSnapshot !== null && $undoSnapshot !== []) {
            $undoBlock = self::serializeUndoSnapshot($undoSnapshot, $resourceKey, $actionName);
            if ($undoBlock !== null) {
                $out = ($out ?? []) + ['undo' => $undoBlock];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private static function serializeUndoSnapshot(array $snapshot, string $resourceKey, string $actionName): ?array
    {
        $max = (int) config('tables.action_log.undo_snapshot_max_bytes', 65536);
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > $max) {
            Log::warning('tables.action_log.undo_truncated', [
                'resource' => $resourceKey,
                'action' => $actionName,
                'size' => $encoded === false ? null : strlen($encoded),
                'limit' => $max,
            ]);

            return [
                '_truncated' => true,
                'size' => $encoded === false ? null : strlen($encoded),
                'captured_at' => now()->toIso8601String(),
            ];
        }

        return [
            'snapshot' => $snapshot,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
