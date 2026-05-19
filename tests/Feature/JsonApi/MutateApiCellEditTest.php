<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class MutateApiCellEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
        Route::tablesApi('/api/orders', MutableOrdersResource::class)->name('orders');
        Route::getRoutes()->refreshNameLookups();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'pending', 'total' => 200.00, 'customer' => 'Bob', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_happy_path_cell_update(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);
        $r->assertOk();
        $body = $r->json();
        $this->assertSame(1, $body['data']['id']);
        $this->assertSame('paid', $body['data']['row']['status']);
        $this->assertArrayNotHasKey('undo', $body);
        $this->assertSame('paid', TestOrder::query()->find(1)?->status);
    }

    public function test_allow_mutations_false_returns_403(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()->allowMutations(false);

        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);
        $r->assertStatus(403);
        $this->assertSame('MUTATIONS_DISABLED', $r->json('error.code'));
    }

    public function test_field_not_in_allow_fields_returns_422(): void
    {
        MutableOrdersResource::$apiOverride = ApiConfig::make()
            ->allowMutations(true)
            ->allowFields(['id', 'total']);

        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('field_not_allowed', $r->json('error.details.reason'));
    }

    public function test_non_editable_field_returns_422(): void
    {
        // total — NumberField без ->editable() → CapabilityUnsupported
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'total',
            'value' => 500,
        ]);
        $r->assertStatus(422);
        $this->assertSame('CAPABILITY_UNSUPPORTED', $r->json('error.code'));
    }

    public function test_validation_fail_in_editor_rules_returns_422(): void
    {
        // editRules: ['required','in:paid,pending,refunded']
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'invalid_value',
        ]);
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $errors = $r->json('error.details.errors.value');
        $this->assertNotEmpty($errors);
    }

    public function test_missing_record_returns_404(): void
    {
        $r = $this->postJson('/api/orders/mutate', [
            'op' => 'cell',
            'id' => 999,
            'field' => 'status',
            'value' => 'paid',
        ]);
        $r->assertStatus(404);
        $this->assertSame('RECORD_NOT_FOUND', $r->json('error.code'));
    }

    public function test_include_undo_token_with_cell_does_not_emit_undo_block(): void
    {
        // cell-edit пока НЕ undoable → undo блок не рендерится даже с ?include=undoToken
        $r = $this->postJson('/api/orders/mutate?include=undoToken', [
            'op' => 'cell',
            'id' => 1,
            'field' => 'status',
            'value' => 'paid',
        ]);
        $r->assertOk();
        $this->assertArrayNotHasKey('undo', $r->json());
    }
}
