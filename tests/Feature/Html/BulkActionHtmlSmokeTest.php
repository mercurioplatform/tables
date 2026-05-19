<?php

namespace Mercurio\Tables\Tests\Feature\Html;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Models\ActionLog;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

/**
 * Smoke-regression тест для HTML-pipeline bulk-action'а.
 *
 * После extract'а `BulkActionHandler::applyAsResult()` HTML-метод `dispatch()`
 * не должен сломаться (XHR-flow + audit log).
 */
final class BulkActionHtmlSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
        Route::tablesPage('/admin/orders', MutableOrdersResource::class)->name('admin.orders');
        Route::getRoutes()->refreshNameLookups();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1', 'status' => 'pending', 'total' => 1, 'customer' => 'A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-2', 'status' => 'pending', 'total' => 2, 'customer' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_bulk_action_xhr_smoke_logs_action(): void
    {
        $r = $this->post('/admin/orders/bulk-action', [
            'action' => 'delete',
            'ids' => [1, 2],
        ], [
            'X-Tables-Partial' => '1',
            'Accept' => 'application/json',
        ]);

        $this->assertTrue($r->isSuccessful() || $r->isRedirection(), "Status: {$r->status()}");
        $this->assertSame(0, TestOrder::query()->count());
        $this->assertSame(1, ActionLog::query()->where('action_name', 'delete')->count());
    }
}
