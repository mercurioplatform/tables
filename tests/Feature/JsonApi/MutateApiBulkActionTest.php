<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Jobs\BulkActionJob;
use Mercurio\Tables\Models\ActionLog;
use Mercurio\Tables\Models\ActionProgress;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class MutateApiBulkActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
        Route::tablesApi('/api/orders', MutableOrdersResource::class)->name('orders');
        Route::getRoutes()->refreshNameLookups();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1', 'status' => 'pending', 'total' => 1, 'customer' => 'A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-2', 'status' => 'pending', 'total' => 2, 'customer' => 'B', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'number' => 'A-3', 'status' => 'pending', 'total' => 3, 'customer' => 'C', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_happy_path_sync_bulk_delete(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'bulk',
            'action' => 'delete',
            'ids' => [1, 2, 3],
        ]);
        $r->assertOk();
        $body = $r->json();
        $this->assertSame(3, $body['data']['affected']);
        $this->assertSame(0, $body['data']['denied']);
        $this->assertSame(0, $body['data']['missing']);
        $this->assertSame(0, TestOrder::query()->count());
    }

    public function test_empty_ids_returns_422(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'bulk',
            'action' => 'delete',
            'ids' => [],
        ]);
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('empty_ids', $r->json('error.details.reason'));
    }

    public function test_ids_exceed_max_bulk_ids(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(true)
            ->maxBulkIds(2);

        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'bulk',
            'action' => 'delete',
            'ids' => [1, 2, 3],
        ]);
        $r->assertStatus(422);
        $this->assertSame('too_many_ids', $r->json('error.details.reason'));
        $this->assertSame(2, $r->json('error.details.max'));
        $this->assertSame(3, $r->json('error.details.given'));
    }

    public function test_unknown_bulk_action_returns_404(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'bulk',
            'action' => 'detonate',
            'ids' => [1],
        ]);
        $r->assertStatus(404);
        $this->assertSame('ACTION_NOT_FOUND', $r->json('error.code'));
    }

    public function test_policy_denied_returns_403(): void
    {
        Gate::define('deleteOrders', fn () => false);

        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'bulk',
            'action' => 'delete-policy',
            'ids' => [1, 2],
        ]);
        $r->assertStatus(403);
        $this->assertSame('POLICY_DENIED', $r->json('error.code'));
    }

    public function test_queued_path_returns_202_envelope(): void
    {
        Queue::fake();

        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'bulk',
            'action' => 'flush-queued',
            'ids' => [1, 2, 3],
        ]);
        $r->assertStatus(202);
        $body = $r->json();
        $this->assertSame('queued', $body['data']['status']);
        $this->assertNotEmpty($body['data']['progress_id']);
        $this->assertSame(3, $body['data']['total']);
        $this->assertSame('Flush', $body['data']['action_label']);

        Queue::assertPushed(BulkActionJob::class);
        $this->assertNotNull(ActionProgress::query()->find($body['data']['progress_id']));
    }

    public function test_include_undo_token_with_sync_delete(): void
    {
        $r = $this->postJson('/api/orders/mutate?include=undoToken', [
            'op' => 'bulk',
            'action' => 'delete',
            'ids' => [1, 2],
        ]);
        $r->assertOk();
        $body = $r->json();
        $this->assertArrayHasKey('undo', $body);
        $this->assertIsInt($body['undo']['token']);
        $this->assertSame('bulk', $body['undo']['kind']);
        $this->assertSame('delete', $body['undo']['action']);
        $this->assertNotNull(ActionLog::query()->find($body['undo']['token']));
    }
}
