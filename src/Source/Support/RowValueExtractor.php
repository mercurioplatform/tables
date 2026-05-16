<?php

namespace Mercurio\Tables\Source\Support;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Mercurio\Tables\Filter\BuiltinFilterEvaluator;
use Mercurio\Tables\Filter\Qb\AtomEvaluator;
use Mercurio\Tables\Source\ArraySource;

/**
 * Извлечение значения row по `field`-имени для in-memory Source-драйверов.
 *
 * Поддерживаемые формы row:
 * - `array<string, mixed>`           — обычный ассоциативный массив.
 * - `Eloquent\Model`                 — через `getAttribute()` (поддержка accessor'ов / casts).
 * - `Arrayable`                      — `toArray()` + ключевой доступ.
 * - `ArrayAccess`                    — `isset($row[$f]) ? $row[$f] : null`.
 * - `object` (public props)          — `$row->{$field}`.
 *
 * Dotted path (`order.customer.email`) — рекурсивный спуск с null-safe
 * прерыванием на первом отсутствующем сегменте.
 *
 * Используется обоими in-memory evaluator'ами:
 * - {@see AtomEvaluator} (QB-фильтры на AtomCondition).
 * - {@see BuiltinFilterEvaluator} (chip-фильтры на FilterCondition).
 *
 * И сортировкой / search-substring внутри
 * {@see ArraySource::withQuery()}.
 */
final class RowValueExtractor
{
    public static function extract(mixed $row, string $field): mixed
    {
        if ($field === '') {
            return null;
        }

        if (str_contains($field, '.')) {
            $value = $row;
            foreach (explode('.', $field) as $segment) {
                if ($value === null) {
                    return null;
                }
                $value = self::readSegment($value, $segment);
            }

            return $value;
        }

        return self::readSegment($row, $field);
    }

    private static function readSegment(mixed $row, string $segment): mixed
    {
        if ($row === null) {
            return null;
        }

        if (is_array($row)) {
            return $row[$segment] ?? null;
        }

        if ($row instanceof Model) {
            return $row->getAttribute($segment);
        }

        if ($row instanceof Arrayable) {
            $arr = $row->toArray();

            return is_array($arr) ? ($arr[$segment] ?? null) : null;
        }

        if ($row instanceof ArrayAccess) {
            return isset($row[$segment]) ? $row[$segment] : null;
        }

        if (is_object($row)) {
            return $row->{$segment} ?? null;
        }

        return null;
    }
}
