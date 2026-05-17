<?php

namespace Mercurio\Tables\Filter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mercurio\Tables\Services\SavedViewCountsCalculator;
use Mercurio\Tables\Source\EloquentSource;

/**
 * Built-in применение {@see FilterCondition} без Field-aware кастомизаций.
 *
 * Используется как fallback в местах, где Field не объявлен (например,
 * условие из source-agnostic `SavedView::conditions(...)` ссылается на
 * колонку, для которой Resource не объявил Field), и как shared-helper
 * для {@see EloquentSource::applyConditions()} и
 * {@see SavedViewCountsCalculator::counts()},
 * чтобы устранить дубликат логики.
 *
 * Семантика операторов идентична устаревшему
 * `FilterApplier::applyBuiltin()` и `EloquentSource::applyBuiltin()`.
 */
final class BuiltinFilterApplier
{
    /**
     * @param  Builder<covariant Model>  $q
     */
    public static function apply(Builder $q, string $column, Operator $op, mixed $value): void
    {
        switch ($op) {
            case Operator::Eq:
                $q->where($column, '=', $value);
                break;
            case Operator::Neq:
                $q->where($column, '!=', $value);
                break;
            case Operator::In:
                $q->whereIn($column, (array) $value);
                break;
            case Operator::NotIn:
                $q->whereNotIn($column, (array) $value);
                break;
            case Operator::Contains:
                $q->where($column, 'like', '%'.self::escapeLike((string) $value).'%');
                break;
            case Operator::NotContains:
                $q->where($column, 'not like', '%'.self::escapeLike((string) $value).'%');
                break;
            case Operator::StartsWith:
                $q->where($column, 'like', self::escapeLike((string) $value).'%');
                break;
            case Operator::NotStartsWith:
                $q->where($column, 'not like', self::escapeLike((string) $value).'%');
                break;
            case Operator::EndsWith:
                $q->where($column, 'like', '%'.self::escapeLike((string) $value));
                break;
            case Operator::NotEndsWith:
                $q->where($column, 'not like', '%'.self::escapeLike((string) $value));
                break;
            case Operator::Between:
                $q->where(function (Builder $qq) use ($column, $value): void {
                    $min = is_array($value) ? ($value[0] ?? null) : null;
                    $max = is_array($value) ? ($value[1] ?? null) : null;
                    if ($min !== null) {
                        $qq->where($column, '>=', $min);
                    }
                    if ($max !== null) {
                        $qq->where($column, '<=', $max);
                    }
                });
                break;
            case Operator::NotBetween:
                $q->where(function (Builder $qq) use ($column, $value): void {
                    $min = is_array($value) ? ($value[0] ?? null) : null;
                    $max = is_array($value) ? ($value[1] ?? null) : null;
                    if ($min !== null && $max !== null) {
                        $qq->whereNotBetween($column, [$min, $max]);
                    } elseif ($min !== null) {
                        $qq->where($column, '<', $min);
                    } elseif ($max !== null) {
                        $qq->where($column, '>', $max);
                    }
                });
                break;
            case Operator::Empty_:
                $q->where(function (Builder $qq) use ($column): void {
                    $qq->whereNull($column)->orWhere($column, '=', '');
                });
                break;
            case Operator::NotEmpty:
                $q->where(function (Builder $qq) use ($column): void {
                    $qq->whereNotNull($column)->where($column, '!=', '');
                });
                break;
            case Operator::Gt:
                $q->where($column, '>', $value);
                break;
            case Operator::Lt:
                $q->where($column, '<', $value);
                break;
            case Operator::Gte:
                $q->where($column, '>=', $value);
                break;
            case Operator::Lte:
                $q->where($column, '<=', $value);
                break;
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
