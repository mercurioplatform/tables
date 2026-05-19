<?php

namespace Mercurio\Tables\Tests\Feature\Html;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Models\ActionLog;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

/**
 * Smoke-regression тест для HTML-pipeline row-action'а.
 *
 * После extract'а `RowActionHandler::applyAsResult()` HTML-метод `dispatch()`
 * остаётся unchanged. Этот тест проверяет, что HTML-flow (XHR + flash + audit log)
 * не сломан.
 */
final class RowActionHtmlSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MutableOrdersResource::reset();
        Route::tablesPage('/admin/orders', MutableOrdersResource::class)->name('admin.orders');
        Route::getRoutes()->refreshNameLookups();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_row_action_xhr_smoke_logs_action(): void
    {
        $r = $this->post('/admin/orders/row-action/1/approve', [], [
            'X-Tables-Partial' => '1',
            'Accept' => 'application/json',
        ]);

        $this->assertTrue($r->isSuccessful() || $r->isRedirection(), "Status: {$r->status()}");
        $this->assertSame(1, ActionLog::query()->where('action_name', 'approve')->count());
    }
}
