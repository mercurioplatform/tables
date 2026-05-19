<?php

namespace Mercurio\Tables\Api;

use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Http\Controllers\JsonApiSchemaController;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\View\SavedView;

/**
 * Сборщик self-describing блока `schema` для JSON API.
 *
 * Один источник правды для двух проекций:
 * - inline-блок envelope'а `?include=schema` (через {@see JsonRenderer});
 * - discovery-endpoint `GET /{uri}/schema` (через
 *   {@see JsonApiSchemaController}).
 *
 * Whitelist через {@see ApiConfig::getAllowFields()} и
 * {@see ApiConfig::getAllowSavedViews()} — публикуется только то, что
 * разрешено API-конфигом ресурса.
 */
final class SchemaBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ListResource $resource, ApiConfig $config, ?Source $source = null): array
    {
        $allowedFields = (array) $config->getAllowFields();
        $allowedFieldsLookup = array_flip($allowedFields);

        $allowedViews = (array) $config->getAllowSavedViews();
        $allowedViewsLookup = array_flip($allowedViews);

        $searchable = $resource->searchable();
        $searchableLookup = array_flip($searchable);

        $fields = [];
        foreach ($resource->fieldsMemo() as $field) {
            if (! array_key_exists($field->name, $allowedFieldsLookup)) {
                continue;
            }
            $fields[$field->name] = $this->serializeField($field, $searchableLookup);
        }

        $savedViews = [];
        foreach ($resource->savedViewsMemo() as $view) {
            if (! array_key_exists($view->key, $allowedViewsLookup)) {
                continue;
            }
            $savedViews[$view->key] = $this->serializeSavedView($view);
        }

        $capabilitiesSource = $source ?? $resource->resolveSource();

        $schema = [
            'resource' => [
                'key' => $resource->key(),
            ],
            'fields' => $fields,
            'savedViews' => $savedViews,
            'capabilities' => $capabilitiesSource->capabilities()->toArray(),
        ];

        return $schema;
    }

    /**
     * @param  array<string, int>  $searchableLookup
     * @return array<string, mixed>
     */
    private function serializeField(Field $field, array $searchableLookup): array
    {
        return [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->schemaType(),
            'sortable' => $field->isSortable(),
            'searchable' => array_key_exists($field->name, $searchableLookup),
            'filterable' => $field->isFilterable(),
            'hidden' => $field->isHidden(),
            'align' => $field->getAlign(),
            'cell_view' => $field->getCellView(),
            'operators' => $this->serializeOperators($field),
            'values' => $this->serializeValues($field),
            'format_hints' => $field->formatHints(),
            'filter_popover' => $field->getFilterPopoverType(),
            'qb_value_type' => $field->getQbValueType(),
            'editable' => $field->isEditable(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function serializeOperators(Field $field): array
    {
        if (! $field->isFilterable()) {
            return [];
        }

        $out = [];
        foreach ($field->getFilterableOperators() as $op) {
            if (! $op instanceof Operator) {
                continue;
            }
            $out[] = [
                'value' => $op->value,
                'label' => $field->operatorLabel($op),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{value: int|string, label: string}>|null
     */
    private function serializeValues(Field $field): ?array
    {
        $options = $field->getFilterOptions();
        if ($options === []) {
            return null;
        }

        $out = [];
        foreach ($options as $value => $label) {
            $out[] = [
                'value' => $value,
                'label' => (string) $label,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSavedView(SavedView $view): array
    {
        return [
            'key' => $view->key,
            'label' => $view->label,
            'default' => $view->isDefault(),
            'color' => $view->getColor(),
            'icon' => $view->getIcon(),
            'position' => $view->getPosition(),
            'conditions' => SavedViewSerializer::serializeConditions($view->conditions),
        ];
    }
}
