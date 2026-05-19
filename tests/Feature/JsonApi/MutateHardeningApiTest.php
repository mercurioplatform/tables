<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class MutateHardeningApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();

        config()->set('cache.default', 'array');

        Route::tablesApi('/api/mutable', MutableOrdersResource::class);
        Route::tablesApi('/api/throttled', MutableOrdersResource::class)
            ->middleware('throttle:1,1');

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'pending', 'total' => 200.00, 'customer' => 'Bob', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'number' => 'A-1003', 'status' => 'pending', 'total' => 300.00, 'customer' => 'Carol', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_coarse_gate_denied_returns_403_policy_denied(): void
    {
        Gate::define('mutate-orders', fn ($user = null) => false);
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(true)
            ->mutateAbility('mutate-orders');

        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);

        $r->assertStatus(403);
        $r->assertHeader('Content-Type', 'application/json');
        $this->assertSame('POLICY_DENIED', $r->json('error.code'));
        $this->assertSame('mutate-orders', $r->json('error.details.ability'));
        $this->assertSame('mutable_orders', $r->json('error.details.resource'));
    }

    public function test_coarse_gate_allowed_falls_through_to_handler(): void
    {
        Gate::define('mutate-orders', fn ($user = null) => true);
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(true)
            ->mutateAbility('mutate-orders');

        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);

        $r->assertStatus(200);
        $r->assertHeader('Content-Type', 'application/json');
    }

    public function test_row_payload_too_large_returns_422(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(true)
            ->maxPayloadBytes(64);

        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'approve',
            'payload' => ['note' => str_repeat('x', 200)],
        ]);

        $r->assertStatus(422);
        $r->assertHeader('Content-Type', 'application/json');
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('payload_too_large', $r->json('error.details.reason'));
        $this->assertSame('row', $r->json('error.details.op'));
        $this->assertSame(64, $r->json('error.details.max'));
        $this->assertGreaterThan(64, $r->json('error.details.given'));
    }

    public function test_bulk_payload_too_large_returns_422(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(true)
            ->maxPayloadBytes(64);

        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'bulk',
            'action' => 'delete',
            'ids' => [1, 2],
            'payload' => ['filter' => str_repeat('y', 200)],
        ]);

        $r->assertStatus(422);
        $r->assertHeader('Content-Type', 'application/json');
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('payload_too_large', $r->json('error.details.reason'));
        $this->assertSame('bulk', $r->json('error.details.op'));
        $this->assertSame(64, $r->json('error.details.max'));
        $this->assertGreaterThan(64, $r->json('error.details.given'));
    }

    public function test_row_payload_within_limit_passes(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()->allowMutations(true);

        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'approve',
            'payload' => ['note' => 'small'],
        ]);

        $r->assertStatus(200);
        $r->assertHeader('Content-Type', 'application/json');
    }

    public function test_allow_mutations_false_takes_priority_over_coarse_gate(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(false)
            ->mutateAbility('mutate-orders');

        $r = $this->postJson('/api/mutable/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);

        $r->assertStatus(403);
        $r->assertHeader('Content-Type', 'application/json');
        $this->assertSame('MUTATIONS_DISABLED', $r->json('error.code'));
        $this->assertSame('mutable_orders', $r->json('error.details.resource'));
    }

    public function test_rate_limit_host_side_throttles_second_request(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()->allowMutations(true);

        $body = [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ];

        $first = $this->postJson('/api/throttled/mutate', $body);
        $first->assertStatus(200);

        $second = $this->postJson('/api/throttled/mutate', $body);
        $second->assertStatus(429);
    }
}
