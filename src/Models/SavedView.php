<?php

namespace Mercurio\Tables\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\Filter\FilterParser;
use Mercurio\Tables\Filter\Qb\QueryBuilderApplier;
use Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use Mercurio\Tables\ListResource;

class SavedView extends Model
{
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('tables.tables.saved_views', 'tables_saved_views'));
        parent::__construct($attributes);
    }

    protected $fillable = [
        'user_id',
        'resource_key',
        'key',
        'name',
        'color',
        'icon',
        'position',
        'is_default',
        'is_system',
        'state_json',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'position' => 'integer',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'state_json' => 'array',
    ];

    public function scopeForResource(Builder $query, string $key): Builder
    {
        return $query->where('resource_key', $key);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true)->whereNull('user_id');
    }

    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $query->where('is_system', false)->where('user_id', $userId);
    }

    public function applyToBuilder(Builder $base, ListResource $resource): void
    {
        $state = $this->state_json ?? [];

        Log::debug('tables.savedviews.apply_state', [
            'key' => $this->key,
            'state_keys' => array_keys($state),
        ]);

        $rawFilters = $state['f'] ?? null;
        if (is_array($rawFilters) && $rawFilters !== []) {
            $conditions = FilterParser::parse($rawFilters, $resource);
            foreach ($conditions as $cond) {
                $field = $resource->findField($cond->field);
                if ($field !== null) {
                    FilterApplier::apply($base, $field, $cond);
                }
            }
        }

        $rawQb = $state['qb'] ?? null;
        if (is_string($rawQb) && $rawQb !== '') {
            $qbRoot = QueryBuilderParser::parse($rawQb, $resource);
            if ($qbRoot !== null) {
                $qbRoot = QueryBuilderNormalizer::normalize($qbRoot);
                $base->where(function (Builder $sub) use ($qbRoot, $resource): void {
                    QueryBuilderApplier::apply($sub, $qbRoot, $resource);
                });
            }
        }

        $sort = $state['sort'] ?? null;
        $dir = $state['dir'] ?? null;
        if (is_string($sort) && $sort !== '') {
            $field = $resource->findField($sort);
            if ($field !== null && $field->isSortable()) {
                $direction = is_string($dir) && strtolower($dir) === 'desc' ? 'desc' : 'asc';
                $base->orderBy($sort, $direction);
            }
        }
    }
}
