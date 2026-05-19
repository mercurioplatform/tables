<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\BrokenSourceResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutateCapabilityOffResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;
use stdClass;

final class ErrorEnvelopeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
        Route::tablesApi('/api/orders', TestOrdersResource::class);
        Route::tablesApi('/api/mutable', MutableOrdersResource::class);
        Route::tablesApi('/api/mutate-off', MutateCapabilityOffResource::class);
        Route::tablesApi('/api/broken', BrokenSourceResource::class);
        Route::tablesApi('/api/notlist', stdClass::class);

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_invalid_format_returns_422_envelope(): void
    {
        $r = $this->getJson('/api/orders?format=xml');
        $r->assertStatus(422);
        $body = $r->json();
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('xml', $body['error']['details']['format']);
    }

    public function test_unknown_field_returns_422(): void
    {
        $r = $this->getJson('/api/orders?fields=secret');
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('secret', $r->json('error.details.field'));
    }

    public function test_disallowed_operator_returns_422(): void
    {
        // number allows only [Eq, Contains]; gte должен 422
        $r = $this->getJson('/api/orders?filter[number][gte]=A-1000');
        $r->assertStatus(422);
        $this->assertSame('number', $r->json('error.details.field'));
        $this->assertSame('gte', $r->json('error.details.operator'));
    }

    public function test_unknown_saved_view_returns_422(): void
    {
        $r = $this->getJson('/api/orders?savedView=hidden');
        $r->assertStatus(422);
        $this->assertSame('hidden', $r->json('error.details.savedView'));
    }

    public function test_per_page_out_of_range_returns_422(): void
    {
        $r = $this->getJson('/api/orders?per_page=999');
        $r->assertStatus(422);
        $body = $r->json();
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame(999, $body['error']['details']['per_page']);
    }

    public function test_page_zero_returns_422(): void
    {
        $r = $this->getJson('/api/orders?page=0');
        $r->assertStatus(422);
    }

    public function test_unknown_include_returns_422(): void
    {
        $r = $this->getJson('/api/orders?include=undocumented');
        $r->assertStatus(422);
        $this->assertSame('undocumented', $r->json('error.details.include'));
    }

    public function test_query_post_non_json_returns_400_malformed_query(): void
    {
        $r = $this->call(
            'POST',
            '/api/orders',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            'raw plain text body',
        );
        $r->assertStatus(400);
        $this->assertSame('MALFORMED_QUERY', $r->json('error.code'));
        $this->assertSame('unsupported_media_type', $r->json('error.details.reason'));
    }

    public function test_query_qb_specified_twice_returns_400(): void
    {
        $r = $this->postJson('/api/orders?qb=eyJvcCI6IkFORCIsImNoaWxkcmVuIjpbXX0=', [
            'qb' => ['op' => 'AND', 'children' => []],
        ]);
        $r->assertStatus(400);
        $this->assertSame('MALFORMED_QUERY', $r->json('error.code'));
        $this->assertSame('qb_specified_twice', $r->json('error.details.reason'));
    }

    public function test_query_unknown_field_in_body_returns_422(): void
    {
        $r = $this->postJson('/api/orders', [
            'fields' => ['secret_undeclared'],
        ]);
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('secret_undeclared', $r->json('error.details.field'));
    }

    public function test_schema_resource_not_found_returns_404(): void
    {
        $r = $this->getJson('/api/notlist/schema');
        $r->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $r->json('error.code'));
        $this->assertSame(stdClass::class, $r->json('error.details.resource'));
    }

    public function test_schema_source_error_returns_500(): void
    {
        $r = $this->getJson('/api/broken/schema');
        $r->assertStatus(500);
        $this->assertSame('SOURCE_ERROR', $r->json('error.code'));
        $this->assertSame(\RuntimeException::class, $r->json('error.details.exception'));
    }

    public function test_mutate_disabled_returns_403(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'id',
            'value' => 'x',
        ]);
        $r->assertStatus(403);
        $this->assertSame('MUTATIONS_DISABLED', $r->json('error.code'));
        $this->assertSame('test_orders', $r->json('error.details.resource'));
    }

    public function test_mutate_capability_unsupported_returns_422(): void
    {
        $r = $this->postJson('/api/mutate-off/mutate', [
            'op' => 'bulk',
            'action' => 'noop',
            'ids' => [1],
        ]);
        $r->assertStatus(422);
        $this->assertSame('CAPABILITY_UNSUPPORTED', $r->json('error.code'));
        $this->assertSame('mutate', $r->json('error.details.capability'));
    }

    public function test_mutate_missing_op_returns_422(): void
    {
        $r = $this->postJson('/api/mutable/mutate', []);
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('missing_op', $r->json('error.details.reason'));
    }

    public function test_mutate_unhandled_throwable_returns_500(): void
    {
        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'throwing',
        ]);
        $r->assertStatus(500);
        $this->assertSame('MUTATION_FAILED', $r->json('error.code'));
        $this->assertSame(\RuntimeException::class, $r->json('error.details.exception'));
    }
}
