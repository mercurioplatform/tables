<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class EloquentSourceMutateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'paid', 'total' => 200.00, 'customer' => 'Bob', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'number' => 'B-2001', 'status' => 'pending', 'total' => 300.00, 'customer' => 'Carol', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_update_returns_fresh_model_with_changes_applied(): void
    {
        $source = new EloquentSource(TestOrder::query());

        $result = $source->update(1, ['status' => 'paid']);

        $this->assertInstanceOf(TestOrder::class, $result);
        $this->assertSame('paid', $result->status);
        $this->assertSame('paid', (string) TestOrder::query()->find(1)->status);
    }

    public function test_update_returns_null_for_missing_id_and_logs_warning(): void
    {
        Log::spy();

        $source = new EloquentSource(TestOrder::query());

        $this->assertNull($source->update(999, ['status' => 'paid']));

        Log::shouldHaveReceived('warning')
            ->with('tables.source.eloquent.update.missing', ['resource' => null, 'id' => 999])
            ->once();
    }

    public function test_update_wraps_changes_in_db_transaction(): void
    {
        $beginnings = 0;
        $commits = 0;

        Event::listen(TransactionBeginning::class, function () use (&$beginnings): void {
            $beginnings++;
        });
        Event::listen(TransactionCommitted::class, function () use (&$commits): void {
            $commits++;
        });

        (new EloquentSource(TestOrder::query()))->update(1, ['status' => 'paid']);

        $this->assertGreaterThanOrEqual(1, $beginnings, 'update() must open a transaction');
        $this->assertGreaterThanOrEqual(1, $commits, 'update() must commit the transaction');
    }
}
