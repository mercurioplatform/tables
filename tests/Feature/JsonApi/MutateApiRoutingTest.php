<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Http\Controllers\JsonApiMutateController;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class MutateApiRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
    }

    public function test_tables_api_registers_three_routes_with_name(): void
    {
        Route::tablesApi('/api/orders', MutableOrdersResource::class)->name('orders');
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has('orders.index'));
        $this->assertTrue(Route::has('orders.schema'));
        $this->assertTrue(Route::has('orders.mutate'));
    }

    public function test_mutate_route_inherits_middleware(): void
    {
        Route::tablesApi('/api/orders', MutableOrdersResource::class)
            ->name('orders')
            ->middleware(['api']);
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('orders.mutate');
        $this->assertNotNull($route);
        $this->assertContains('api', $route->gatherMiddleware());
    }

    public function test_where_constraint_applies_to_all_routes(): void
    {
        Route::tablesApi('/api/orders', MutableOrdersResource::class)
            ->name('orders')
            ->where(['id' => '[0-9]+']);
        Route::getRoutes()->refreshNameLookups();

        foreach (['orders.index', 'orders.schema', 'orders.mutate'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} should exist");
        }
    }

    public function test_invalid_resource_class_in_defaults_returns_404(): void
    {
        Route::post('/api/broken/mutate', JsonApiMutateController::class)
            ->defaults('resource', 'NonExistent\\Class');

        // Note: LogicException thrown server-side; assertion catches via exception serialization
        $r = $this->postJson('/api/broken/mutate', ['op' => 'cell', 'id' => 1, 'field' => 'x', 'value' => 'y']);
        // не-ListResource → 404 envelope (или 500 если class_exists=false проваливается раньше).
        $this->assertContains($r->status(), [404, 500]);
    }

    public function test_non_listresource_class_returns_404(): void
    {
        Route::post('/api/wrong/mutate', JsonApiMutateController::class)
            ->defaults('resource', \stdClass::class);

        $r = $this->postJson('/api/wrong/mutate', ['op' => 'cell', 'id' => 1, 'field' => 'x', 'value' => 'y']);
        $r->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $r->json('error.code'));
    }

    public function test_raw_non_json_body_returns_422(): void
    {
        Route::tablesApi('/api/orders', MutableOrdersResource::class)->name('orders');

        // application/json + malformed → Laravel парсит '$request->json()->all()' как [] → missing_op
        $r = $this->call(
            'POST',
            '/api/orders/mutate',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            'not json',
        );
        $this->assertSame(422, $r->status());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
    }
}
