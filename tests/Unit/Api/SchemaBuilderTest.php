<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\SchemaBuilder;
use Mercurio\Tables\Tests\Fixtures\JsonApi\RichFieldsResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SchemaBuilder;
    }

    public function test_top_level_shape(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame(['resource', 'fields', 'savedViews', 'capabilities'], array_keys($schema));
        $this->assertSame(['key' => 'rich_fields'], $schema['resource']);
    }

    public function test_fields_default_resolution_includes_all(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertCount(5, $schema['fields']);
        $this->assertSame(
            ['id', 'status', 'total', 'created_at', 'verified'],
            array_keys($schema['fields']),
        );
    }

    public function test_fields_whitelist_via_allow_fields(): void
    {
        $resource = new RichFieldsResource;
        // Берём resolved config (sentinel'ы allowFields/allowSavedViews уже раскрыты
        // из ресурса) и переопределяем только allowFields — SchemaBuilder требует
        // оба whitelist'а не-null.
        $config = $resource->resolveApiConfig()->allowFields(['id', 'total']);
        $schema = $this->builder->build($resource, $config);

        $this->assertCount(2, $schema['fields']);
        $this->assertSame(['id', 'total'], array_keys($schema['fields']));
    }

    public function test_field_shape_has_expected_keys(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $expected = [
            'name', 'label', 'type', 'sortable', 'searchable', 'filterable',
            'hidden', 'align', 'cell_view', 'operators', 'values', 'format_hints',
            'filter_popover', 'qb_value_type', 'editable',
        ];
        $this->assertSame($expected, array_keys($schema['fields']['id']));
    }

    public function test_schema_type_per_field_class(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame('text', $schema['fields']['id']['type']);
        $this->assertSame('status', $schema['fields']['status']['type']);
        $this->assertSame('money', $schema['fields']['total']['type']);
        $this->assertSame('date', $schema['fields']['created_at']['type']);
        $this->assertSame('boolean', $schema['fields']['verified']['type']);
    }

    public function test_format_hints_money(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());
        $hints = $schema['fields']['total']['format_hints'];

        $this->assertSame('₽', $hints['currency']);
        $this->assertSame(100, $hints['divisor']);
        $this->assertSame('after', $hints['position']);
        $this->assertSame(2, $hints['decimals']);
        $this->assertArrayHasKey('decimal_separator', $hints);
        $this->assertArrayHasKey('thousands_separator', $hints);
    }

    public function test_format_hints_status(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());
        $hints = $schema['fields']['status']['format_hints'];

        $this->assertSame(['paid' => 'success', 'pending' => 'warning'], $hints['kinds']);
        $this->assertSame(['paid' => 'Оплачен', 'pending' => 'Ожидание'], $hints['labels']);
    }

    public function test_format_hints_date(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());
        $hints = $schema['fields']['created_at']['format_hints'];

        $this->assertSame('absolute', $hints['mode']);
        $this->assertSame('d.m.Y', $hints['format']);
    }

    public function test_format_hints_boolean(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());
        $hints = $schema['fields']['verified']['format_hints'];

        $this->assertSame('Да', $hints['true_label']);
        $this->assertSame('Нет', $hints['false_label']);
    }

    public function test_operators_structure_with_localized_labels(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $operators = $schema['fields']['status']['operators'];
        $this->assertSame(
            [
                ['value' => 'eq', 'label' => 'Равно'],
                ['value' => 'in', 'label' => 'В списке'],
            ],
            $operators,
        );
    }

    public function test_values_for_status_field(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame(
            [
                ['value' => 'paid', 'label' => 'Оплачен'],
                ['value' => 'pending', 'label' => 'Ожидание'],
            ],
            $schema['fields']['status']['values'],
        );
    }

    public function test_values_for_text_field_is_null(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertNull($schema['fields']['id']['values']);
    }

    public function test_searchable_mapping(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertTrue($schema['fields']['id']['searchable']);
        $this->assertFalse($schema['fields']['total']['searchable']);
    }

    public function test_sortable_flag_is_propagated(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertTrue($schema['fields']['id']['sortable']);
        $this->assertFalse($schema['fields']['verified']['sortable']);
    }

    public function test_hidden_flag(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertTrue($schema['fields']['verified']['hidden']);
        $this->assertFalse($schema['fields']['id']['hidden']);
    }

    public function test_align_propagated(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame('right', $schema['fields']['total']['align']);
        $this->assertSame('left', $schema['fields']['id']['align']);
    }

    public function test_filter_popover_and_qb_value_type(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame('select', $schema['fields']['status']['filter_popover']);
        $this->assertSame('select', $schema['fields']['status']['qb_value_type']);
        $this->assertSame('range', $schema['fields']['total']['filter_popover']);
        $this->assertSame('number', $schema['fields']['total']['qb_value_type']);
    }

    public function test_editable_flag_default_false(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        foreach ($schema['fields'] as $field) {
            $this->assertFalse($field['editable']);
        }
    }

    public function test_saved_views_default_resolution(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame(['all', 'paid', 'big'], array_keys($schema['savedViews']));
    }

    public function test_saved_views_whitelist(): void
    {
        $resource = new TestOrdersResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        // TestOrdersResource->api() явно allowSavedViews(['all', 'paid'])
        $this->assertSame(['all', 'paid'], array_keys($schema['savedViews']));
        $this->assertArrayNotHasKey('hidden', $schema['savedViews']);
    }

    public function test_saved_view_shape_and_conditions(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());
        $paid = $schema['savedViews']['paid'];

        $this->assertSame(
            ['key', 'label', 'default', 'color', 'icon', 'position', 'conditions'],
            array_keys($paid),
        );
        $this->assertSame('paid', $paid['key']);
        $this->assertTrue($paid['default']);
        $this->assertSame('success', $paid['color']);
        $this->assertSame('check', $paid['icon']);
        $this->assertSame(1, $paid['position']);
        $this->assertSame(
            [['field' => 'status', 'operator' => 'eq', 'value' => 'paid']],
            $paid['conditions'],
        );
    }

    public function test_capabilities_block_present_and_includes_qbtree(): void
    {
        $resource = new RichFieldsResource;
        $schema = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertArrayHasKey('capabilities', $schema);
        $caps = $schema['capabilities'];
        $this->assertTrue($caps['filter']);
        $this->assertTrue($caps['sort']);
        $this->assertArrayHasKey('qbTree', $caps);
        $this->assertIsBool($caps['qbTree']);
    }

    public function test_source_reuse_path_uses_passed_source(): void
    {
        $resource = new RichFieldsResource;
        $source = $resource->resolveSource();

        $passed = $this->builder->build($resource, $resource->resolveApiConfig(), $source);
        $derived = $this->builder->build($resource, $resource->resolveApiConfig());

        $this->assertSame($passed['capabilities'], $derived['capabilities']);
        $this->assertSame($passed, $derived);
    }
}
