<?php

namespace Mercurio\Tables\Filter\Support;

use Mercurio\Tables\Filter\FilterParser;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Shared нормализация raw-значений из chip-фильтра ({@see FilterParser}) и
 * Query Builder AST ({@see QueryBuilderParser}). Семантика операторов In/Between
 * приведена к единому виду: range → `[min, max]` (поддерживая `{min, max}` или
 * позиционный массив), in/not_in → отфильтрованный список scalar строк.
 */
final class ValueNormalizer
{
    public static function normalize(Operator $operator, mixed $raw): mixed
    {
        $listOps = [Operator::In, Operator::NotIn];
        $rangeOps = [Operator::Between, Operator::NotBetween];

        if (in_array($operator, $rangeOps, true)) {
            if (! is_array($raw)) {
                return null;
            }
            if (array_key_exists('min', $raw) || array_key_exists('max', $raw)) {
                $min = self::scalarOrNull($raw['min'] ?? null);
                $max = self::scalarOrNull($raw['max'] ?? null);

                return ($min === null && $max === null) ? null : [$min, $max];
            }
            $values = array_values($raw);
            $min = self::scalarOrNull($values[0] ?? null);
            $max = self::scalarOrNull($values[1] ?? null);

            return ($min === null && $max === null) ? null : [$min, $max];
        }

        if (in_array($operator, $listOps, true)) {
            $items = is_array($raw) ? array_values($raw) : [$raw];
            $cleaned = [];
            foreach ($items as $item) {
                $s = self::scalarOrNull($item);
                if ($s !== null) {
                    $cleaned[] = (string) $s;
                }
            }

            return $cleaned === [] ? null : $cleaned;
        }

        if (is_array($raw)) {
            return null;
        }

        return self::scalarOrNull($raw);
    }

    public static function scalarOrNull(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $t = trim($v);

            return $t === '' ? null : $t;
        }
        if (is_scalar($v)) {
            return $v;
        }

        return null;
    }
}
