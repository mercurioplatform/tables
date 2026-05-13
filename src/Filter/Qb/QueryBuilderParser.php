<?php

namespace Mercurio\Tables\Filter\Qb;

use JsonException;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Operator}, {@see ListResource::query()}.
 */
final class QueryBuilderParser
{
    public static function parse(?string $rawBase64, ListResource $resource): ?AtomGroup
    {
        if ($rawBase64 === null || $rawBase64 === '') {
            return null;
        }

        $maxPayload = (int) config('tables.qb_max_payload_size', 4096);
        if (strlen($rawBase64) > $maxPayload) {
            return null;
        }

        $json = base64_decode($rawBase64, strict: true);
        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode($json, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $fields = [];
        foreach ($resource->fieldsMemo() as $field) {
            $fields[$field->name] = $field;
        }

        $maxDepth = (int) config('tables.qb_max_depth', 5);
        $maxAtoms = (int) config('tables.qb_max_atoms', 100);

        $state = ['atoms' => 0, 'depth' => 0, 'rejected' => []];

        $rootType = $decoded['type'] ?? null;
        if ($rootType === 'cond') {
            $cond = self::walkCondition($decoded, $fields, $state);
            $root = $cond !== null
                ? new AtomGroup('AND', false, [$cond])
                : null;
        } elseif ($rootType === 'group') {
            $root = self::walkGroup($decoded, $fields, 0, $maxDepth, $maxAtoms, $state);
        } else {
            $state['rejected'][] = 'root:invalid_type';
            $root = null;
        }

        if ($root !== null && $root->children === []) {
            $root = null;
        }

        return $root;
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array{atoms: int, depth: int, rejected: array<int, string>}  $state
     */
    private static function walkGroup(
        array $node,
        array $fields,
        int $currentDepth,
        int $maxDepth,
        int $maxAtoms,
        array &$state,
    ): ?AtomGroup {
        if ($currentDepth > $maxDepth) {
            $state['rejected'][] = 'group:depth_exceeded';

            return null;
        }
        if ($currentDepth > $state['depth']) {
            $state['depth'] = $currentDepth;
        }

        $op = $node['op'] ?? null;
        if ($op !== 'AND' && $op !== 'OR') {
            $state['rejected'][] = 'group:invalid_op';

            return null;
        }

        $not = (bool) ($node['not'] ?? false);
        $rawChildren = $node['children'] ?? [];
        if (! is_array($rawChildren)) {
            $state['rejected'][] = 'group:children_not_array';

            return null;
        }

        $children = [];
        foreach ($rawChildren as $childNode) {
            if (! is_array($childNode)) {
                $state['rejected'][] = 'child:not_array';

                continue;
            }
            $childType = $childNode['type'] ?? null;
            if ($childType === 'cond') {
                if ($state['atoms'] >= $maxAtoms) {
                    $state['rejected'][] = 'cond:atoms_limit';

                    continue;
                }
                $cond = self::walkCondition($childNode, $fields, $state);
                if ($cond !== null) {
                    $children[] = $cond;
                    $state['atoms']++;
                }
            } elseif ($childType === 'group') {
                $sub = self::walkGroup($childNode, $fields, $currentDepth + 1, $maxDepth, $maxAtoms, $state);
                if ($sub !== null && $sub->children !== []) {
                    $children[] = $sub;
                }
            } else {
                $state['rejected'][] = 'child:invalid_type';
            }
        }

        if ($children === [] && $currentDepth > 0) {
            return null;
        }

        return new AtomGroup($op, $not, $children);
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array{atoms: int, depth: int, rejected: array<int, string>}  $state
     */
    private static function walkCondition(array $node, array $fields, array &$state): ?AtomCondition
    {
        $fieldName = $node['field'] ?? null;
        if (! is_string($fieldName) || $fieldName === '') {
            $state['rejected'][] = 'cond:field_invalid';

            return null;
        }

        $field = $fields[$fieldName] ?? null;
        if ($field === null || ! $field->isFilterable()) {
            $state['rejected'][] = $fieldName.':unknown_field';

            return null;
        }

        $opStr = $node['operator'] ?? null;
        if (! is_string($opStr)) {
            $state['rejected'][] = $fieldName.':op_not_string';

            return null;
        }
        $operator = Operator::tryFrom($opStr);
        if ($operator === null) {
            $state['rejected'][] = $fieldName.':unknown_op:'.$opStr;

            return null;
        }

        $allowed = $field->getFilterableOperators();
        if ($allowed !== [] && ! in_array($operator, $allowed, true)) {
            $state['rejected'][] = $fieldName.':operator_not_allowed:'.$opStr;

            return null;
        }

        $not = (bool) ($node['not'] ?? false);
        $rawValue = $node['value'] ?? null;

        $isEmptyOp = in_array($operator, [Operator::Empty_, Operator::NotEmpty], true);

        if ($isEmptyOp) {
            $value = null;
        } else {
            $normalized = self::normalizeValue($operator, $rawValue);
            if ($normalized === null) {
                $state['rejected'][] = $fieldName.':empty_value';

                return null;
            }
            $value = $field->normalizeFilterValue($normalized);
            if ($value === null) {
                $state['rejected'][] = $fieldName.':value_normalized_to_null';

                return null;
            }
        }

        return new AtomCondition($fieldName, $operator, $value, $not);
    }

    private static function normalizeValue(Operator $operator, mixed $raw): mixed
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

    private static function scalarOrNull(mixed $v): mixed
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
