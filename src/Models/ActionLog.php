<?php

namespace Mercurio\Tables\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $resource_key
 * @property string $action_name
 * @property string $kind
 * @property int|null $actor_id
 * @property array<string, mixed>|null $payload_json
 * @property array<string, mixed>|null $subjects_json
 * @property array<string, mixed>|null $result_json
 * @property Carbon $created_at
 */
class ActionLog extends Model
{
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('tables.tables.action_log', 'tables_action_log'));
        parent::__construct($attributes);
    }

    public $timestamps = false;

    protected $fillable = [
        'resource_key',
        'action_name',
        'kind',
        'actor_id',
        'payload_json',
        'subjects_json',
        'result_json',
        'created_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'payload_json' => 'array',
        'subjects_json' => 'array',
        'result_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeForResource(Builder $query, string $key): Builder
    {
        return $query->where('resource_key', $key);
    }
}
