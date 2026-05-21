<?php

namespace Mercurio\Tables\Filter;

use Illuminate\Database\Eloquent\Builder;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\ListResource;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see ListResource::query()}.
 */
final class FilterApplier
{
    public static function apply(Builder $query, Field $field, FilterCondition $cond): void
    {
        if ($field->applyFilter($query, $cond->operator, $cond->value)) {
            return;
        }

        if (($using = $field->getFilterUsing()) !== null) {
            $using($query, $cond->operator, $cond->value);
        } elseif (($scope = $field->getFilterScope()) !== null) {
            $query->{$scope}($cond->operator, $cond->value);
        } else {
            BuiltinFilterApplier::apply($query, $field->getFilterColumn(), $cond->operator, $cond->value);
        }
    }
}
