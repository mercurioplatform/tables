<?php

namespace Mercurio\Tables\Source\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mercurio\Tables\Source\SqlSource;

/**
 * Concrete inline-Model для {@see SqlSource::for()}.
 *
 * Голый {@see Model}-subclass без relations / scopes / observers / accessors —
 * нужен лишь для того, чтобы получить из {@see Model::newQuery()} реальный
 * {@see Builder} поверх произвольной таблицы /
 * connection. Анонимный subclass не подходит из-за PHPStan template-invariance
 * `Builder<Model@anonymous>` ↦ `Builder<Model>`; concrete subclass решает это
 * без потери семантики (relations / observers / accessors в обоих случаях
 * отсутствуют).
 *
 * Не предназначен для прямого использования host'ом — entry point всегда
 * {@see SqlSource::for()}.
 *
 * @internal
 */
final class SqlSourceModel extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}
