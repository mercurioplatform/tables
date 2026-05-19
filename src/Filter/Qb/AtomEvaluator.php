<?php

namespace Mercurio\Tables\Filter\Qb;

use Mercurio\Tables\Filter\BuiltinFilterEvaluator;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Support\RowValueExtractor;

/**
 * In-memory эвалюатор AST из Query Builder'а (`?qb=` → AtomCondition / AtomGroup).
 *
 * Семантически эквивалентен {@see QueryBuilderApplier} (Eloquent), но работает
 * над одной row-value парой и возвращает `bool`. Reused by:
 * - {@see ArraySource}.
 * - `HttpSource` — client-side fallback для колонок, которые API не
 *   умеет фильтровать на сервере.
 * - `FileSource` — CSV/JSONL.
 *
 * **Field-aware customizations не поддерживаются.** В Eloquent-стеке
 * `QueryBuilderApplier::apply()` принимает `$resource` и пускает atom'ы через
 * Field-aware `FilterApplier` (Field::applyFilter / filterUsing / filterScope)
 * c fallback'ом на `BuiltinFilterApplier`. Кастомные `Closure(Builder, ...)`
 * на in-memory row применить невозможно — AtomEvaluator **всегда** идёт через
 * built-in operator semantics ({@see BuiltinFilterEvaluator}). ArraySource при
 * detection кастомизации пишет один WARN per-field
 * (`array_source.field_filter_customization_skipped`).
 */
final class AtomEvaluator
{
    public static function matches(mixed $row, AtomCondition|AtomGroup $atom): bool
    {
        if ($atom instanceof AtomGroup) {
            return self::evaluateGroup($row, $atom);
        }

        return self::evaluateCondition($row, $atom);
    }

    private static function evaluateGroup(mixed $row, AtomGroup $group): bool
    {
        if ($group->children === []) {
            // Пустая группа эквивалентна SQL `WHERE ()` без условий —
            // не отсекает ничего. Инверсия `not` отрицает этот no-op до false.
            return ! $group->not;
        }

        if ($group->op === 'AND') {
            $result = true;
            foreach ($group->children as $child) {
                if (! self::matches($row, $child)) {
                    $result = false;
                    break;
                }
            }
        } else {
            $result = false;
            foreach ($group->children as $child) {
                if (self::matches($row, $child)) {
                    $result = true;
                    break;
                }
            }
        }

        return $group->not ? ! $result : $result;
    }

    private static function evaluateCondition(mixed $row, AtomCondition $cond): bool
    {
        $value = RowValueExtractor::extract($row, $cond->field);
        $matched = BuiltinFilterEvaluator::matches($value, $cond->operator, $cond->value);

        return $cond->not ? ! $matched : $matched;
    }
}
