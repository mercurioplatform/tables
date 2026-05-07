<?php

namespace Mercurio\Tables\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Mercurio\Tables\Action\ActionResult;

class ActionProgress extends Model
{
    use HasUuids;

    public function __construct(array $attributes = [])
    {
        $this->setTable(config('tables.tables.action_progress', 'tables_action_progress'));
        parent::__construct($attributes);
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'resource_key',
        'action_name',
        'kind',
        'actor_id',
        'status',
        'total',
        'processed',
        'affected',
        'missing',
        'denied',
        'skipped',
        'affected_ids_json',
        'payload_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'total' => 'integer',
        'processed' => 'integer',
        'affected' => 'integer',
        'missing' => 'integer',
        'denied' => 'integer',
        'skipped' => 'integer',
        'affected_ids_json' => 'array',
        'payload_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeForActor(Builder $q, ?int $actorId): Builder
    {
        return $actorId === null
            ? $q->whereNull('actor_id')
            : $q->where('actor_id', $actorId);
    }

    public function scopeForResource(Builder $q, string $key): Builder
    {
        return $q->where('resource_key', $key);
    }

    /**
     * @param  array<int, mixed>  $chunkIds
     */
    public function recordChunkResult(ActionResult $result, array $chunkIds): void
    {
        $existingIds = $this->affected_ids_json ?? [];
        $cap = (int) config('tables.bulk_progress.max_affected_ids_for_cta', 200);
        $merged = array_values(array_unique(array_merge($existingIds, array_values($chunkIds))));
        $newIds = array_slice($merged, 0, max(0, $cap));

        $this->forceFill([
            'processed' => $this->processed + count($chunkIds),
            'affected' => $this->affected + $result->affected,
            'missing' => $this->missing + $result->missing,
            'denied' => $this->denied + $result->denied,
            'skipped' => $this->skipped + $result->skipped,
            'affected_ids_json' => $newIds,
        ])->save();
    }
}
