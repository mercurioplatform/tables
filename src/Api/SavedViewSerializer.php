<?php

namespace Mercurio\Tables\Api;

use Mercurio\Tables\Filter\FilterCondition;

/**
 * Общий helper сериализации saved-view conditions для JSON API-поверхностей.
 *
 * Используется {@see JsonRenderer::serializeSavedView()} (inline
 * блок `savedViews`) и {@see SchemaBuilder} (блок
 * `schema.savedViews.<key>.conditions`). Один источник правды — структура
 * `{field, operator, value}` остаётся идентичной во всех проекциях.
 */
final class SavedViewSerializer
{
    /**
     * @param  array<int, FilterCondition>  $conditions
     * @return array<int, array{field: string, operator: string, value: mixed}>
     */
    public static function serializeConditions(array $conditions): array
    {
        $out = [];
        foreach ($conditions as $cond) {
            $out[] = [
                'field' => $cond->field,
                'operator' => $cond->operator->value,
                'value' => $cond->value,
            ];
        }

        return $out;
    }
}
