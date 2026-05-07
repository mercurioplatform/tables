<?php

namespace Mercurio\Tables\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserTablePrefs extends Model
{
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('tables.tables.user_table_prefs', 'tables_user_table_prefs'));
        parent::__construct($attributes);
    }

    protected $fillable = [
        'user_id',
        'resource_key',
        'prefs_json',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'prefs_json' => 'array',
    ];

    public function scopeForResource(Builder $query, string $key): Builder
    {
        return $query->where('resource_key', $key);
    }

    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public static function findFor(int $userId, string $resourceKey): ?self
    {
        return self::query()->forUser($userId)->forResource($resourceKey)->first();
    }

    /**
     * @param  array<string, mixed>  $prefs
     */
    public static function upsertFor(int $userId, string $resourceKey, array $prefs): self
    {
        $row = self::firstOrNew([
            'user_id' => $userId,
            'resource_key' => $resourceKey,
        ]);
        $row->prefs_json = $prefs;
        $row->save();

        return $row;
    }
}
