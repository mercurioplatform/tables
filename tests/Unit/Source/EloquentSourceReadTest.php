<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class EloquentSourceReadTest extends TestCase
{
    private function seedOrders(int $n): void
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'id' => $i,
                'number' => sprintf('A-%04d', $i),
                'status' => 'pending',
                'total' => 100.00 * $i,
                'customer' => "Customer #{$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        TestOrder::query()->insert($rows);
    }

    public function test_count_returns_total_rows(): void
    {
        $this->seedOrders(5);

        $this->assertSame(5, (new EloquentSource(TestOrder::query()))->count());
    }

    public function test_page_on_empty_input_returns_empty_page(): void
    {
        $page = (new EloquentSource(TestOrder::query()))->page(1, 10);

        $this->assertSame([], $page->rows);
        $this->assertSame(0, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->prevCursor);
    }

    public function test_page_second_page_offset_pagination(): void
    {
        $this->seedOrders(5);

        $page = (new EloquentSource(TestOrder::query()))->page(2, 2);

        $this->assertCount(2, $page->rows);
        $this->assertSame([3, 4], array_map(static fn (TestOrder $o): int => (int) $o->id, $page->rows));
        $this->assertSame(5, $page->total);
        $this->assertSame(2, $page->page);
        $this->assertSame(2, $page->perPage);
    }

    public function test_find_returns_model_for_existing_id(): void
    {
        $this->seedOrders(3);

        $found = (new EloquentSource(TestOrder::query()))->find(1);

        $this->assertInstanceOf(TestOrder::class, $found);
        $this->assertSame(1, (int) $found->id);
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->seedOrders(3);

        $this->assertNull((new EloquentSource(TestOrder::query()))->find(999));
    }

    public function test_find_many_empty_array_returns_empty(): void
    {
        $this->assertSame([], (new EloquentSource(TestOrder::query()))->findMany([]));
    }

    public function test_find_many_returns_matched_models_for_existing_ids(): void
    {
        $this->seedOrders(3);

        $rows = (new EloquentSource(TestOrder::query()))->findMany([1, 3]);

        $this->assertCount(2, $rows);
        $ids = array_map(static fn (TestOrder $o): int => (int) $o->id, $rows);
        sort($ids);
        $this->assertSame([1, 3], $ids);
    }

    public function test_find_many_skips_missing_ids(): void
    {
        $this->seedOrders(3);

        $rows = (new EloquentSource(TestOrder::query()))->findMany([1, 999]);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->id);
    }

    public function test_stream_yields_all_rows_in_chunks(): void
    {
        $this->seedOrders(5);

        $rows = iterator_to_array((new EloquentSource(TestOrder::query()))->stream(2), false);

        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertInstanceOf(TestOrder::class, $row);
        }
    }

    public function test_stream_on_empty_input_yields_nothing(): void
    {
        $rows = iterator_to_array((new EloquentSource(TestOrder::query()))->stream(2), false);

        $this->assertSame([], $rows);
    }
}
