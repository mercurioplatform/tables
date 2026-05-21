<?php

namespace Mercurio\Tables\Filter;

use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Support\ValueNormalizer;
use Mercurio\Tables\ListResource;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Operator}, {@see Field::filterable()}.
 */
final class FilterParser
{
    /**
     * @param  array<string, mixed>  $raw
     * @return FilterCondition[]
     */
    public static function parse(array $raw, ListResource $resource): array
    {
        $fields = $resource->fieldsMemo();
        $byName = [];
        $order = [];
        foreach ($fields as $idx => $field) {
            $byName[$field->name] = $field;
            $order[$field->name] = $idx;
        }

        $conditions = [];
        $rejected = [];

        foreach ($raw as $fieldName => $opMap) {
            if (! is_string($fieldName) || $fieldName === '') {
                $rejected[] = (is_string($fieldName) ? $fieldName : '<non-string>').':invalid_key';

                continue;
            }

            $field = $byName[$fieldName] ?? null;
            if ($field === null || ! $field->isFilterable()) {
                $rejected[] = $fieldName.':unknown_field';

                continue;
            }

            if (! is_array($opMap)) {
                $rejected[] = $fieldName.':invalid_op_map';

                continue;
            }

            $allowedOps = $field->getFilterableOperators();

            foreach ($opMap as $opKey => $rawValue) {
                if (! is_string($opKey)) {
                    $rejected[] = $fieldName.':non_string_op';

                    continue;
                }

                $operator = Operator::tryFrom($opKey);
                if ($operator === null) {
                    $rejected[] = $fieldName.':unknown_op:'.$opKey;

                    continue;
                }

                if ($allowedOps !== [] && ! in_array($operator, $allowedOps, true)) {
                    $rejected[] = $fieldName.':operator_not_allowed:'.$opKey;

                    continue;
                }

                $isEmptyOp = in_array($operator, [Operator::Empty_, Operator::NotEmpty], true);

                if (! $isEmptyOp) {
                    $normalized = ValueNormalizer::normalize($operator, $rawValue);
                    if ($normalized === null) {
                        $rejected[] = $fieldName.':empty_value';

                        continue;
                    }
                } else {
                    $normalized = null;
                }

                $value = $field->normalizeFilterValue($normalized);

                $conditions[] = [
                    'order' => $order[$fieldName],
                    'cond' => new FilterCondition($fieldName, $operator, $value),
                ];
            }
        }

        usort($conditions, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(fn ($entry) => $entry['cond'], $conditions);
    }
}
