<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Http\Controllers\JsonApiSchemaController;
use Mercurio\Tables\Tests\Fixtures\JsonApi\BrokenSourceResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class SchemaDiscoveryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/orders', TestOrdersResource::class)->name('orders');
    }

    public function test_discovery_endpoint_returns_only_schema_block(): void
    {
        $body = $this->getJson('/api/orders/schema')->assertOk()->json();

        $this->assertSame(['schema'], array_keys($body));
        $this->assertSame(
            ['resource', 'fields', 'savedViews', 'capabilities'],
            array_keys($body['schema']),
        );
        $this->assertSame('test_orders', $body['schema']['resource']['key']);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('page', $body);
    }

    public function test_inline_and_discovery_schema_blocks_are_identical(): void
    {
        $inline = $this->getJson('/api/orders?include=schema')->assertOk()->json();
        $discovery = $this->getJson('/api/orders/schema')->assertOk()->json();

        $this->assertSame($inline['schema'], $discovery['schema']);
    }

    public function test_schema_route_is_named_and_resolvable(): void
    {
        $url = route('orders.schema');
        $this->assertStringEndsWith('/api/orders/schema', $url);
    }

    public function test_middleware_applies_to_all_routes(): void
    {
        Route::tablesApi('/api/guarded', TestOrdersResource::class)
            ->name('guarded')
            ->middleware('auth');

        $this->getJson('/api/guarded')->assertStatus(401);
        $this->getJson('/api/guarded/schema')->assertStatus(401);
    }

    public function test_where_constraints_apply_to_all_routes(): void
    {
        // Регистрируем под /api/widgets/{id} с constraint id=\d+; schema-роут /api/widgets/{id}/schema
        // должен наследовать ту же constraint, потому что PendingTablesApiResource применяет where ко всем routes
        Route::tablesApi('/api/widgets/{id}', TestOrdersResource::class)
            ->name('widgets')
            ->where(['id' => '[0-9]+']);

        $this->getJson('/api/widgets/abc/schema')->assertStatus(404);
        $this->getJson('/api/widgets/42/schema')->assertOk();
    }

    public function test_allow_fields_whitelist_applies_to_discovery(): void
    {
        $body = $this->getJson('/api/orders/schema')->assertOk()->json();

        $this->assertSame(['id', 'number', 'status', 'total', 'customer'], array_keys($body['schema']['fields']));
    }

    public function test_list_route_still_works_alongside_schema_route(): void
    {
        $this->getJson('/api/orders')->assertOk();
        $this->getJson('/api/orders/schema')->assertOk();
    }

    public function test_invalid_resource_class_returns_404_envelope(): void
    {
        Route::get('/api/broken/schema', JsonApiSchemaController::class)
            ->defaults('resource', \stdClass::class);

        $r = $this->getJson('/api/broken/schema');
        $r->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $r->json('error.code'));
    }

    public function test_broken_source_returns_500_envelope(): void
    {
        Route::tablesApi('/api/broken-src', BrokenSourceResource::class);

        $r = $this->getJson('/api/broken-src/schema');
        $r->assertStatus(500);
        $this->assertSame('SOURCE_ERROR', $r->json('error.code'));
    }
}
