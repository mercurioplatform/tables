<?php

namespace Mercurio\Tables\Filter\Qb;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see \Mercurio\Tables\Filter\Operator}, {@see \Mercurio\Tables\ListResource::query()}.
 */
final class QueryBuilderNormalizer
{
    public static function normalize(AtomGroup $root): ?AtomGroup
    {
        $iter = 0;
        $current = $root;
        do {
            $changed = false;
            $applied = [];
            $next = self::applyAll($current, $applied);

            if ($next === null) {
                return null;
            }

            if (! self::nodesEqual($current, $next)) {
                $changed = true;
            }
            $current = $next;
            $iter++;
        } while ($changed && $iter < 8);

        return $current;
    }

    /**
     * @param  array<int, string>  $rulesApplied
     */
    private static function applyAll(AtomGroup $node, array &$rulesApplied): ?AtomGroup
    {
        return self::normalizeGroup($node, $rulesApplied, isRoot: true);
    }

    /**
     * @param  array<int, string>  $rulesApplied
     */
    private static function normalizeGroup(AtomGroup $group, array &$rulesApplied, bool $isRoot): ?AtomGroup
    {
        $newChildren = [];
        foreach ($group->children as $child) {
            if ($child instanceof AtomCondition) {
                $newChildren[] = self::normalizeCondition($child, $rulesApplied);
            } else {
                $sub = self::normalizeGroup($child, $rulesApplied, isRoot: false);
                if ($sub === null) {
                    $rulesApplied[] = 'drop_empty_group';

                    continue;
                }
                if ($sub->op === $group->op && ! $sub->not) {
                    $rulesApplied[] = 'flatten_same_op';
                    foreach ($sub->children as $grand) {
                        $newChildren[] = $grand;
                    }

                    continue;
                }
                $newChildren[] = $sub;
            }
        }

        if ($newChildren === []) {
            if ($isRoot) {
                return null;
            }

            return null;
        }

        if (count($newChildren) === 1 && $group->not) {
            $only = $newChildren[0];
            if ($only instanceof AtomCondition) {
                $rulesApplied[] = 'push_not_to_atom';
                $only = new AtomCondition($only->field, $only->operator, $only->value, ! $only->not);
                $only = self::normalizeCondition($only, $rulesApplied);
                if ($isRoot) {
                    return new AtomGroup($group->op, false, [$only]);
                }

                return new AtomGroup($group->op, false, [$only]);
            }
            if ($only instanceof AtomGroup) {
                $rulesApplied[] = 'push_not_to_subgroup';
                $only = new AtomGroup($only->op, ! $only->not, $only->children);
                if ($isRoot) {
                    return new AtomGroup($group->op, false, [$only]);
                }

                return $only;
            }
        }

        if (count($newChildren) === 1 && ! $group->not && ! $isRoot) {
            $only = $newChildren[0];
            if ($only instanceof AtomGroup) {
                $rulesApplied[] = 'unwrap_single_child_group';

                return $only;
            }
        }

        return new AtomGroup($group->op, $group->not, $newChildren);
    }

    /**
     * @param  array<int, string>  $rulesApplied
     */
    private static function normalizeCondition(AtomCondition $cond, array &$rulesApplied): AtomCondition
    {
        if ($cond->not && $cond->operator->pair() !== null) {
            $rulesApplied[] = 'flip_not_pair';

            return new AtomCondition($cond->field, $cond->operator->pair(), $cond->value, false);
        }

        return $cond;
    }

    public static function countAtoms(AtomCondition|AtomGroup $node): int
    {
        if ($node instanceof AtomCondition) {
            return 1;
        }
        $total = 0;
        foreach ($node->children as $child) {
            $total += self::countAtoms($child);
        }

        return $total;
    }

    public static function maxDepth(AtomCondition|AtomGroup $node, int $current = 0): int
    {
        if ($node instanceof AtomCondition) {
            return $current;
        }
        if ($node->children === []) {
            return $current;
        }
        $max = $current;
        foreach ($node->children as $child) {
            $d = self::maxDepth($child, $current + 1);
            if ($d > $max) {
                $max = $d;
            }
        }

        return $max;
    }

    private static function nodesEqual(AtomCondition|AtomGroup $a, AtomCondition|AtomGroup $b): bool
    {
        if ($a instanceof AtomCondition && $b instanceof AtomCondition) {
            return $a->field === $b->field
                && $a->operator === $b->operator
                && $a->value === $b->value
                && $a->not === $b->not;
        }
        if ($a instanceof AtomGroup && $b instanceof AtomGroup) {
            if ($a->op !== $b->op || $a->not !== $b->not) {
                return false;
            }
            if (count($a->children) !== count($b->children)) {
                return false;
            }
            foreach ($a->children as $i => $childA) {
                if (! self::nodesEqual($childA, $b->children[$i])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
