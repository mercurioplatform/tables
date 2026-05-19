<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent fixture для mutate-тестов.
 *
 * Используется {@see MutableOrdersResource} поверх SQLite-in-memory.
 *
 * @property int $id
 * @property string $number
 * @property string $status
 * @property string $total
 * @property string $customer
 */
final class TestOrder extends Model
{
    protected $table = 'test_orders';

    protected $fillable = ['number', 'status', 'total', 'customer'];

    protected $casts = [
        'total' => 'decimal:2',
    ];
}
