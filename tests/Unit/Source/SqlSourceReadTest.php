<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mercurio\Tables\Source\SqlSource;
use Mercurio\Tables\Tests\TestCase;

final class SqlSourceReadTest extends TestCase
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
        DB::table('test_orders')->insert($rows);
    }

    public function test_count_returns_total_rows(): void
    {
        $this->seedOrders(5);

        $this->assertSame(5, SqlSource::for('test_orders')->count());
    }

    public function test_count_on_empty_table_returns_zero(): void
    {
        $this->assertSame(0, SqlSource::for('test_orders')->count());
    }

    public function test_page_on_empty_table_returns_empty_page(): void
    {
        $page = SqlSource::for('test_orders')->page(1, 10);

        $this->assertSame([], $page->rows);
        $this->assertSame(0, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->prevCursor);
    }

    public function test_page_first_page_returns_first_n_rows(): void
    {
        $this->seedOrders(5);

        $page = SqlSource::for('test_orders')->page(1, 2);

        $ids = array_map(static fn (Model $m): int => (int) $m->id, $page->rows);
        $this->assertSame([1, 2], $ids);
        $this->assertSame(5, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(2, $page->perPage);
    }

    public function test_page_second_page_offset_pagination(): void
    {
        $this->seedOrders(5);

        $page = SqlSource::for('test_orders')->page(2, 2);

        $ids = array_map(static fn (Model $m): int => (int) $m->id, $page->rows);
        $this->assertSame([3, 4], $ids);
        $this->assertSame(5, $page->total);
        $this->assertSame(2, $page->page);
    }

    public function test_page_beyond_last_returns_empty_rows_but_correct_total(): void
    {
        $this->seedOrders(5);

        $page = SqlSource::for('test_orders')->page(99, 10);

        $this->assertSame([], $page->rows);
        $this->assertSame(5, $page->total);
    }

    public function test_find_returns_row_for_existing_id(): void
    {
        $this->seedOrders(3);

        $result = SqlSource::for('test_orders')->find(1);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) data_get($result, 'id'));
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->seedOrders(3);

        $this->assertNull(SqlSource::for('test_orders')->find(999));
    }

    public function test_find_many_empty_array_returns_empty(): void
    {
        $this->assertSame([], SqlSource::for('test_orders')->findMany([]));
    }

    public function test_find_many_returns_matched_rows_for_existing_ids(): void
    {
        $this->seedOrders(3);

        $rows = iterator_to_array(
            (function (): \Generator {
                foreach (SqlSource::for('test_orders')->findMany([1, 3]) as $row) {
                    yield $row;
                }
            })(),
            false,
        );

        $this->assertCount(2, $rows);
        $ids = array_map(static fn (Model $m): int => (int) $m->id, $rows);
        sort($ids);
        $this->assertSame([1, 3], $ids);
    }

    public function test_find_many_skips_missing_ids(): void
    {
        $this->seedOrders(3);

        $rows = SqlSource::for('test_orders')->findMany([1, 999]);

        $this->assertCount(1, $rows);
        $ids = array_map(static fn (Model $m): int => (int) $m->id, (array) $rows);
        $this->assertSame([1], $ids);
    }

    public function test_find_many_does_not_preserve_order_of_input(): void
    {
        $this->seedOrders(5);

        $rows = SqlSource::for('test_orders')->findMany([3, 1, 5]);

        $this->assertCount(3, $rows);
        $ids = array_map(static fn (Model $m): int => (int) $m->id, (array) $rows);
        sort($ids);
        $this->assertSame([1, 3, 5], $ids, 'findMany() docblock §5.3: order not preserved — assert presence, not order');
    }

    public function test_stream_yields_all_rows_in_chunks(): void
    {
        $this->seedOrders(5);

        $items = iterator_to_array(SqlSource::for('test_orders')->stream(2), false);

        $this->assertCount(5, $items);
        foreach ($items as $item) {
            $this->assertInstanceOf(Model::class, $item);
        }
    }

    public function test_stream_on_empty_table_yields_nothing(): void
    {
        $items = iterator_to_array(SqlSource::for('test_orders')->stream(2), false);

        $this->assertSame([], $items);
    }
}
