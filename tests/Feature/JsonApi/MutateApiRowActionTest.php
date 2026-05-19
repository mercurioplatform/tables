<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Models\ActionLog;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class MutateApiRowActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
        Route::tablesApi('/api/orders', MutableOrdersResource::class)->name('orders');
        Route::getRoutes()->refreshNameLookups();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_happy_path_row_action(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'approve',
        ]);
        $r->assertOk();
        $body = $r->json();
        $this->assertSame(1, $body['data']['id']);
        $this->assertSame(1, $body['data']['affected']);
        $this->assertSame('approved', $body['data']['message']);
        $this->assertArrayNotHasKey('undo', $body);
    }

    public function test_unknown_action_returns_404(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'transmogrify',
        ]);
        $r->assertStatus(404);
        $this->assertSame('ACTION_NOT_FOUND', $r->json('error.code'));
    }

    public function test_policy_denied_returns_403(): void
    {
        Gate::define('approveOrders', fn () => false);

        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'approve-policy',
        ]);
        $r->assertStatus(403);
        $this->assertSame('POLICY_DENIED', $r->json('error.code'));
    }

    public function test_missing_record_returns_404(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'row',
            'id' => 999,
            'action' => 'approve',
        ]);
        $r->assertStatus(404);
        $this->assertSame('RECORD_NOT_FOUND', $r->json('error.code'));
    }

    public function test_callback_throws_returns_500_with_exception_class(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'throwing',
        ]);
        $r->assertStatus(500);
        $this->assertSame('MUTATION_FAILED', $r->json('error.code'));
        $this->assertSame(\RuntimeException::class, $r->json('error.details.exception'));
    }

    public function test_include_undo_token_returns_log_id(): void
    {
        $r = $this->postJson('/api/orders/mutate?include=undoToken', [
            'op' => 'row',
            'id' => 1,
            'action' => 'approve',
        ]);
        $r->assertOk();
        $body = $r->json();
        $this->assertArrayHasKey('undo', $body);
        $this->assertIsInt($body['undo']['token']);
        $this->assertSame('row', $body['undo']['kind']);
        $this->assertSame('approve', $body['undo']['action']);
        $this->assertNotNull(ActionLog::query()->find($body['undo']['token']));
    }

    public function test_link_action_unsupported(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'row',
            'id' => 1,
            'action' => 'open-link',
        ]);
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('open-link', $r->json('error.details.action'));
    }
}
