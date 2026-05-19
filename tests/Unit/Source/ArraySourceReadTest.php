<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Tests\TestCase;

final class ArraySourceReadTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function makeRows(int $n): array
    {
        return array_map(static fn (int $i): array => ['id' => $i, 'name' => "r{$i}"], range(1, $n));
    }

    public function test_count_returns_total_rows(): void
    {
        $this->assertSame(5, (new ArraySource($this->makeRows(5)))->count());
    }

    public function test_count_on_empty_returns_zero(): void
    {
        $this->assertSame(0, (new ArraySource([]))->count());
    }

    public function test_page_on_empty_input_returns_empty_page(): void
    {
        $page = (new ArraySource([]))->page(1, 10);

        $this->assertSame([], $page->rows);
        $this->assertSame(0, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->prevCursor);
        $this->assertNotNull($page->delegate, 'LengthAwarePaginator-shim присутствует даже для пустой страницы');
    }

    public function test_page_second_page_offset_pagination(): void
    {
        $page = (new ArraySource($this->makeRows(5)))->page(2, 2);

        $this->assertCount(2, $page->rows);
        $this->assertSame([3, 4], array_map(static fn (array $r): int => $r['id'], $page->rows));
        $this->assertSame(5, $page->total);
        $this->assertSame(2, $page->page);
        $this->assertSame(2, $page->perPage);
    }

    public function test_page_clamps_page_below_one_to_one(): void
    {
        $page = (new ArraySource($this->makeRows(3)))->page(0, 10);

        $this->assertSame(1, $page->page);
        $this->assertCount(3, $page->rows);
    }

    public function test_page_clamps_per_page_below_one_to_one(): void
    {
        $page = (new ArraySource($this->makeRows(3)))->page(1, 0);

        $this->assertSame(1, $page->perPage);
        $this->assertSame(1, $page->page);
        $this->assertSame(3, $page->total);
        $this->assertCount(1, $page->rows);
        $this->assertSame([1], array_map(static fn (array $r): int => $r['id'], $page->rows));
    }

    public function test_page_out_of_range_returns_empty_rows(): void
    {
        $page = (new ArraySource($this->makeRows(3)))->page(99, 10);

        $this->assertSame([], $page->rows);
        $this->assertSame(3, $page->total);
        $this->assertSame(99, $page->page);
        $this->assertSame(10, $page->perPage);
    }

    public function test_find_by_int_id_returns_row(): void
    {
        $hit = (new ArraySource($this->makeRows(3)))->find(2);

        $this->assertSame(['id' => 2, 'name' => 'r2'], $hit);
    }

    public function test_find_with_string_coerced_id(): void
    {
        $hit = (new ArraySource([['id' => 42, 'name' => 'r42']]))->find('42');

        $this->assertSame(['id' => 42, 'name' => 'r42'], $hit);
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->assertNull((new ArraySource($this->makeRows(3)))->find(999));
    }

    public function test_find_uses_custom_primary_key(): void
    {
        $hit = (new ArraySource([['code' => 'X', 'v' => 1]], primaryKey: 'code'))->find('X');

        $this->assertSame(['code' => 'X', 'v' => 1], $hit);
    }

    public function test_find_many_empty_array_early_returns(): void
    {
        $this->assertSame([], (new ArraySource($this->makeRows(3)))->findMany([]));
    }

    public function test_find_many_preserves_input_order(): void
    {
        $rows = (new ArraySource($this->makeRows(3)))->findMany([3, 1, 2]);

        $this->assertCount(3, $rows);
        $this->assertSame([3, 1, 2], array_map(static fn (array $r): int => $r['id'], (array) $rows));
    }

    public function test_find_many_preserves_duplicate_ids_in_input(): void
    {
        $rows = (new ArraySource($this->makeRows(3)))->findMany([1, 1, 2]);

        $this->assertCount(3, $rows);
        $this->assertSame([1, 1, 2], array_map(static fn (array $r): int => $r['id'], (array) $rows));
    }

    public function test_find_many_skips_missing_ids(): void
    {
        $rows = (new ArraySource($this->makeRows(2)))->findMany([1, 999, 2]);

        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_map(static fn (array $r): int => $r['id'], (array) $rows));
    }

    public function test_stream_yields_all_rows_in_chunks(): void
    {
        $rows = iterator_to_array((new ArraySource($this->makeRows(5)))->stream(2), preserve_keys: false);

        $this->assertCount(5, $rows);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (array $r): int => $r['id'], $rows));
    }

    public function test_stream_on_empty_input_yields_nothing(): void
    {
        $rows = iterator_to_array((new ArraySource([]))->stream(2));

        $this->assertSame([], $rows);
    }

    public function test_stream_clamps_chunk_size_below_one_to_one(): void
    {
        $rows = iterator_to_array((new ArraySource($this->makeRows(3)))->stream(0), preserve_keys: false);

        $this->assertCount(3, $rows);
    }
}
