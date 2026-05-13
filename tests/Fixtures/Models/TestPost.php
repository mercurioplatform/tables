<?php

namespace Mercurio\Tables\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $status
 * @property string|null $body
 * @property Carbon|null $published_at
 */
class TestPost extends Model
{
    protected $table = 'test_posts';

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
