<?php

namespace Mercurio\Tables\Filter\Qb;

use Illuminate\Database\Eloquent\Builder;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Operator}, {@see ListResource::query()}.
 */
final class QueryBuilderApplier
{
    public static function apply(Builder $query, AtomGroup $root, ListResource $resource): void
    {
        $fields = [];
        foreach ($resource->fieldsMemo() as $field) {
            $fields[$field->name] = $field;
        }

        self::applyGroup($query, $root, $fields, 'and');
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  'and'|'or'  $context
     */
    private static function applyGroup(Builder $query, AtomGroup $group, array $fields, string $context): void
    {
        if ($group->children === []) {
            return;
        }

        $closure = function (Builder $inner) use ($group, $fields): void {
            foreach ($group->children as $idx => $child) {
                $childContext = ($idx === 0)
                    ? 'and'
                    : ($group->op === 'OR' ? 'or' : 'and');

                if ($child instanceof AtomCondition) {
                    self::applyCondition($inner, $child, $fields, $childContext);
                } else {
                    self::applyGroup($inner, $child, $fields, $childContext);
                }
            }
        };

        $method = match (true) {
            $context === 'or' && $group->not => 'orWhereNot',
            $context === 'or' => 'orWhere',
            $group->not => 'whereNot',
            default => 'where',
        };

        $query->{$method}($closure);
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  'and'|'or'  $context
     */
    private static function applyCondition(Builder $query, AtomCondition $cond, array $fields, string $context): void
    {
        $field = $fields[$cond->field] ?? null;
        if ($field === null) {
            return;
        }

        $closure = function (Builder $q) use ($field, $cond): void {
            FilterApplier::apply($q, $field, FilterCondition::fromAtom($cond));
        };

        $method = match (true) {
            $context === 'or' && $cond->not => 'orWhereNot',
            $context === 'or' => 'orWhere',
            $cond->not => 'whereNot',
            default => 'where',
        };

        $query->{$method}($closure);
    }
}
