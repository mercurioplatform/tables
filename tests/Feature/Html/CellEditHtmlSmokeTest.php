<?php

namespace Mercurio\Tables\Tests\Feature\Html;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

/**
 * Smoke-regression тест для HTML-pipeline cell-edit'а.
 *
 * После extract'а pure-метода `CellUpdateHandler::applyAsArray()` HTML-метод
 * `handle()` оборачивает pure-логику в Blade-view. Этот тест утверждает,
 * что HTML response shape (status, body fragment) не сломан.
 */
final class CellEditHtmlSmokeTest extends TestCase
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

    public function test_cell_edit_returns_html_row_fragment(): void
    {
        $r = $this->patch('/admin/orders/cells/1/status', ['value' => 'paid'], [
            'X-Tables-Partial' => '1',
            'Accept' => 'text/html',
        ]);
        $r->assertOk();
        $this->assertStringContainsString('text/html', (string) $r->headers->get('content-type'));
        $this->assertSame('paid', TestOrder::query()->find(1)?->status);
    }
}
