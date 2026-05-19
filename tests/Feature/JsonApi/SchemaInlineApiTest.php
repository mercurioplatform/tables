<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class SchemaInlineApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/orders', TestOrdersResource::class)->name('orders');
    }

    public function test_include_schema_appends_block_alongside_data_and_page(): void
    {
        $body = $this->getJson('/api/orders?include=schema')->assertOk()->json();

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('page', $body);
        $this->assertArrayHasKey('schema', $body);
        $this->assertSame(['resource', 'fields', 'savedViews', 'capabilities'], array_keys($body['schema']));
        $this->assertSame('test_orders', $body['schema']['resource']['key']);
    }

    public function test_include_schema_combines_with_other_blocks(): void
    {
        $body = $this->getJson('/api/orders?include=schema,summary,capabilities')->assertOk()->json();

        foreach (['data', 'page', 'summary', 'capabilities', 'schema'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
        $this->assertArrayNotHasKey('savedViews', $body);
    }

    public function test_default_envelope_has_no_schema(): void
    {
        $body = $this->getJson('/api/orders')->assertOk()->json();

        $this->assertArrayNotHasKey('schema', $body);
    }

    public function test_fields_param_affects_data_but_not_schema(): void
    {
        $body = $this->getJson('/api/orders?include=schema&fields=id,total')->assertOk()->json();

        // data only id+total
        foreach ($body['data'] as $row) {
            $this->assertSame(['id', 'total'], array_keys($row));
        }

        // schema всё ещё включает все allowFields (5 полей TestOrdersResource)
        $this->assertSame(
            ['id', 'number', 'status', 'total', 'customer'],
            array_keys($body['schema']['fields']),
        );
    }

    public function test_allow_saved_views_restricts_schema_saved_views(): void
    {
        $body = $this->getJson('/api/orders?include=schema')->assertOk()->json();

        // TestOrdersResource->api() allowSavedViews(['all', 'paid']); 'hidden' исключён
        $this->assertSame(['all', 'paid'], array_keys($body['schema']['savedViews']));
    }

    public function test_schema_capabilities_match_inline_capabilities(): void
    {
        $body = $this->getJson('/api/orders?include=schema,capabilities')->assertOk()->json();

        $this->assertSame($body['capabilities'], $body['schema']['capabilities']);
    }

    public function test_schema_operators_carry_russian_labels(): void
    {
        $body = $this->getJson('/api/orders?include=schema')->assertOk()->json();
        $statusOps = $body['schema']['fields']['status']['operators'];

        $labels = array_column($statusOps, 'label', 'value');
        $this->assertSame('Равно', $labels['eq']);
        $this->assertSame('В списке', $labels['in']);
    }
}
