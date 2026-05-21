<?php

namespace Mercurio\Tables\Filter\Qb;

use JsonException;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\Exceptions\QueryBuilderValidationException;
use Mercurio\Tables\Filter\Support\ValueNormalizer;
use Mercurio\Tables\ListResource;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Operator}, {@see ListResource::query()}.
 *
 * Два entry-point'а:
 * - {@see self::parse()} — base64-encoded JSON → AtomGroup; вход из GET `?qb=<base64>`
 *   и из UI-путей (`SavedView`, `FilterPipeline`).
 * - {@see self::parseArray()} — уже декодированный массив-словарь → AtomGroup; вход
 *   из POST `application/json` body, где Laravel сам распаковал JSON.
 *
 * Параметр `$strict`:
 * - `false` (default, UI back-compat): невалидные ноды silently dropped в
 *   `state['rejected']`, walker возвращает `null` для нод, которые не прошли.
 *   Существующие UI callers (SavedView::resolve, FilterPipeline::apply)
 *   полагаются на этот контракт.
 * - `true` (API-путь): каждый reject throw'ает
 *   {@see QueryBuilderValidationException} с `kind`/`field`/`operator`.
 *   ApiQueryParser маппит это в HTTP 422/400.
 */
final class QueryBuilderParser
{
    public static function parse(?string $rawBase64, ListResource $resource, bool $strict = false): ?AtomGroup
    {
        if ($rawBase64 === null || $rawBase64 === '') {
            return null;
        }

        $maxPayload = (int) config('tables.qb_max_payload_size', 4096);
        if (strlen($rawBase64) > $maxPayload) {
            return self::rejectOrNull(
                $strict,
                QueryBuilderValidationException::KIND_PAYLOAD_TOO_LARGE,
                details: ['size' => strlen($rawBase64), 'limit' => $maxPayload],
            );
        }

        $json = base64_decode($rawBase64, strict: true);
        if ($json === false) {
            return self::rejectOrNull(
                $strict,
                QueryBuilderValidationException::KIND_MALFORMED_BASE64,
            );
        }

        try {
            $decoded = json_decode($json, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::rejectOrNull(
                $strict,
                QueryBuilderValidationException::KIND_MALFORMED_JSON,
                details: ['json_error' => $e->getMessage()],
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            return self::rejectOrNull(
                $strict,
                QueryBuilderValidationException::KIND_MALFORMED_JSON,
                details: ['reason' => 'json_root_not_object_or_array'],
            );
        }

        return self::parseArray($decoded, $resource, $strict);
    }

    /**
     * @param  array<mixed, mixed>  $tree
     */
    public static function parseArray(array $tree, ListResource $resource, bool $strict = false): ?AtomGroup
    {
        $fields = [];
        foreach ($resource->fieldsMemo() as $field) {
            $fields[$field->name] = $field;
        }

        $maxDepth = (int) config('tables.qb_max_depth', 5);
        $maxAtoms = (int) config('tables.qb_max_atoms', 100);

        $state = ['atoms' => 0, 'depth' => 0, 'rejected' => []];

        $rootType = $tree['type'] ?? null;

        if ($rootType === 'cond') {
            $cond = self::walkCondition($tree, $fields, $state, $strict);
            $root = $cond !== null
                ? new AtomGroup('AND', false, [$cond])
                : null;
        } elseif ($rootType === 'group') {
            $root = self::walkGroup($tree, $fields, 0, $maxDepth, $maxAtoms, $state, $strict);
        } else {
            $state['rejected'][] = 'root:invalid_type';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_UNKNOWN_NODE_TYPE,
                    details: ['received' => is_string($rootType) ? $rootType : gettype($rootType)],
                );
            }
            $root = null;
        }

        if ($root !== null && $root->children === []) {
            $root = null;
        }

        return $root;
    }

    /**
     * @param  array<mixed, mixed>  $node
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
        bool $strict,
    ): ?AtomGroup {
        if ($currentDepth > $maxDepth) {
            $state['rejected'][] = 'group:depth_exceeded';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_DEPTH_EXCEEDED,
                    details: ['depth' => $currentDepth, 'limit' => $maxDepth],
                );
            }

            return null;
        }
        if ($currentDepth > $state['depth']) {
            $state['depth'] = $currentDepth;
        }

        $op = $node['op'] ?? null;
        if ($op !== 'AND' && $op !== 'OR') {
            $state['rejected'][] = 'group:invalid_op';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_INVALID_OP,
                    details: ['received' => is_string($op) ? $op : gettype($op)],
                );
            }

            return null;
        }

        $not = (bool) ($node['not'] ?? false);
        $rawChildren = $node['children'] ?? [];
        if (! is_array($rawChildren)) {
            $state['rejected'][] = 'group:children_not_array';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_UNKNOWN_NODE_TYPE,
                    details: ['reason' => 'children_not_array'],
                );
            }

            return null;
        }

        $children = [];
        foreach ($rawChildren as $childNode) {
            if (! is_array($childNode)) {
                $state['rejected'][] = 'child:not_array';
                if ($strict) {
                    throw new QueryBuilderValidationException(
                        QueryBuilderValidationException::KIND_UNKNOWN_NODE_TYPE,
                        details: ['reason' => 'child_not_array'],
                    );
                }

                continue;
            }
            $childType = $childNode['type'] ?? null;
            if ($childType === 'cond') {
                if ($state['atoms'] >= $maxAtoms) {
                    $state['rejected'][] = 'cond:atoms_limit';
                    if ($strict) {
                        throw new QueryBuilderValidationException(
                            QueryBuilderValidationException::KIND_ATOMS_EXCEEDED,
                            details: ['limit' => $maxAtoms],
                        );
                    }

                    continue;
                }
                $cond = self::walkCondition($childNode, $fields, $state, $strict);
                if ($cond !== null) {
                    $children[] = $cond;
                    $state['atoms']++;
                }
            } elseif ($childType === 'group') {
                $sub = self::walkGroup($childNode, $fields, $currentDepth + 1, $maxDepth, $maxAtoms, $state, $strict);
                if ($sub !== null && $sub->children !== []) {
                    $children[] = $sub;
                }
            } else {
                $state['rejected'][] = 'child:invalid_type';
                if ($strict) {
                    throw new QueryBuilderValidationException(
                        QueryBuilderValidationException::KIND_UNKNOWN_NODE_TYPE,
                        details: ['received' => is_string($childType) ? $childType : gettype($childType)],
                    );
                }
            }
        }

        if ($children === [] && $currentDepth > 0) {
            return null;
        }

        return new AtomGroup($op, $not, $children);
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @param  array<string, Field>  $fields
     * @param  array{atoms: int, depth: int, rejected: array<int, string>}  $state
     */
    private static function walkCondition(array $node, array $fields, array &$state, bool $strict): ?AtomCondition
    {
        $fieldName = $node['field'] ?? null;
        if (! is_string($fieldName) || $fieldName === '') {
            $state['rejected'][] = 'cond:field_invalid';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_UNKNOWN_FIELD,
                    details: ['reason' => 'field_missing_or_not_string'],
                );
            }

            return null;
        }

        $field = $fields[$fieldName] ?? null;
        if ($field === null || ! $field->isFilterable()) {
            $state['rejected'][] = $fieldName.':unknown_field';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_UNKNOWN_FIELD,
                    field: $fieldName,
                );
            }

            return null;
        }

        $opStr = $node['operator'] ?? null;
        if (! is_string($opStr)) {
            $state['rejected'][] = $fieldName.':op_not_string';
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_OPERATOR_NOT_ALLOWED,
                    field: $fieldName,
                    details: ['reason' => 'operator_missing_or_not_string'],
                );
            }

            return null;
        }
        $operator = Operator::tryFrom($opStr);
        if ($operator === null) {
            $state['rejected'][] = $fieldName.':unknown_op:'.$opStr;
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_OPERATOR_NOT_ALLOWED,
                    field: $fieldName,
                    operator: $opStr,
                    details: ['reason' => 'operator_unknown'],
                );
            }

            return null;
        }

        $allowed = $field->getFilterableOperators();
        if ($allowed !== [] && ! in_array($operator, $allowed, true)) {
            $state['rejected'][] = $fieldName.':operator_not_allowed:'.$opStr;
            if ($strict) {
                throw new QueryBuilderValidationException(
                    QueryBuilderValidationException::KIND_OPERATOR_NOT_ALLOWED,
                    field: $fieldName,
                    operator: $opStr,
                );
            }

            return null;
        }

        $not = (bool) ($node['not'] ?? false);
        $rawValue = $node['value'] ?? null;

        $isEmptyOp = in_array($operator, [Operator::Empty_, Operator::NotEmpty], true);

        if ($isEmptyOp) {
            $value = null;
        } else {
            $normalized = ValueNormalizer::normalize($operator, $rawValue);
            if ($normalized === null) {
                $state['rejected'][] = $fieldName.':empty_value';
                if ($strict) {
                    throw new QueryBuilderValidationException(
                        QueryBuilderValidationException::KIND_EMPTY_VALUE,
                        field: $fieldName,
                        operator: $opStr,
                    );
                }

                return null;
            }
            $value = $field->normalizeFilterValue($normalized);
            if ($value === null) {
                $state['rejected'][] = $fieldName.':value_normalized_to_null';
                if ($strict) {
                    throw new QueryBuilderValidationException(
                        QueryBuilderValidationException::KIND_VALUE_NOT_NORMALIZABLE,
                        field: $fieldName,
                        operator: $opStr,
                    );
                }

                return null;
            }
        }

        return new AtomCondition($fieldName, $operator, $value, $not);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function rejectOrNull(
        bool $strict,
        string $kind,
        ?string $field = null,
        ?string $operator = null,
        array $details = [],
        ?Throwable $previous = null,
    ): null {
        if ($strict) {
            throw new QueryBuilderValidationException($kind, $field, $operator, $details, previous: $previous);
        }

        return null;
    }
}
