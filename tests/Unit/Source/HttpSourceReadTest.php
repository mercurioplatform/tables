<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class HttpSourceReadTest extends TestCase
{
    /** @var array<int, array{query: Query, cursor: ?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    private function recordingFetch(array $pages): Closure
    {
        $i = 0;

        return function (Query $q, ?string $cursor) use (&$i, $pages): array {
            $this->calls[] = ['query' => clone $q, 'cursor' => $cursor];
            $page = $pages[$i] ?? end($pages);
            $i++;

            return $page;
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $from, int $to): array
    {
        $rows = [];
        for ($i = $from; $i <= $to; $i++) {
            $rows[] = ['id' => $i, 'name' => "Item #{$i}"];
        }

        return $rows;
    }

    public function test_count_returns_null_by_default_without_fetcher_call(): void
    {
        $result = HttpSource::for($this->recordingFetch([['rows' => [], 'total' => 99]]))->count();

        $this->assertNull($result);
        $this->assertSame([], $this->calls, 'fetcher must not be invoked when capabilities.count=false');
    }

    public function test_count_with_capability_returns_total_from_payload(): void
    {
        $result = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => $this->rows(1, 3), 'total' => 42]]),
            capabilities: new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: true,
                cursor: false,
                mutate: false,
                stream: true,
            ),
        )->count();

        $this->assertSame(42, $result);
        $this->assertSame('offset:1', $this->calls[0]['cursor']);
    }

    public function test_page_cursor_mode_default_without_count(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => $this->rows(1, 2), 'nextCursor' => 'cur-2', 'prevCursor' => null],
        ]))->withQuery(new Query);

        $page = $source->page(1, 10);

        $this->assertCount(2, $page->rows);
        $this->assertSame(1, $page->rows[0]['id']);
        $this->assertSame(2, $page->rows[1]['id']);
        $this->assertNull($page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertSame('cur-2', $page->nextCursor);
        $this->assertNull($page->prevCursor);
        $this->assertNull($this->calls[0]['cursor'], 'withQuery resets internal cursor to null');
    }

    public function test_page_offset_mode_when_count_capability_enabled(): void
    {
        $source = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => $this->rows(3, 4), 'total' => 10]]),
            capabilities: new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: true,
                cursor: false,
                mutate: false,
                stream: true,
            ),
        )->withQuery(new Query);

        $page = $source->page(2, 2);

        $this->assertSame(10, $page->total);
        $this->assertSame(2, $page->page);
        $this->assertSame(2, $page->perPage);
        $this->assertCount(2, $page->rows);
        $this->assertSame('offset:2', $this->calls[0]['cursor']);
    }

    public function test_page_clamps_page_below_one_to_one(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => $this->rows(1, 1), 'nextCursor' => null],
        ]))->withQuery(new Query);

        $page = $source->page(0, 10);

        $this->assertSame(1, $page->page);
        $this->assertNull($this->calls[0]['cursor']);
    }

    public function test_page_clamps_per_page_below_one_to_one(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => $this->rows(1, 1), 'nextCursor' => null],
        ]))->withQuery(new Query);

        $page = $source->page(1, 0);

        $this->assertSame(1, $page->perPage);
    }

    public function test_page_empty_payload_returns_empty_page_without_cursors(): void
    {
        $source = HttpSource::for($this->recordingFetch([['rows' => []]]))->withQuery(new Query);

        $page = $source->page(1, 10);

        $this->assertSame([], $page->rows);
        $this->assertNull($page->total);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->prevCursor);
    }

    public function test_stream_yields_rows_across_pages_via_cursor(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => [['id' => 1], ['id' => 2]], 'nextCursor' => 'c1'],
            ['rows' => [['id' => 3], ['id' => 4]], 'nextCursor' => 'c2'],
            ['rows' => [['id' => 5]], 'nextCursor' => null],
        ]))->withQuery(new Query);

        $result = iterator_to_array($source->stream(2), false);

        $this->assertCount(5, $result);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $result);
        $this->assertSame([1, 2, 3, 4, 5], $ids);

        $this->assertCount(3, $this->calls);
        $this->assertNull($this->calls[0]['cursor']);
        $this->assertSame('c1', $this->calls[1]['cursor']);
        $this->assertSame('c2', $this->calls[2]['cursor']);
    }

    public function test_stream_clamps_chunk_size_below_one_to_one(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => $this->rows(1, 3), 'nextCursor' => null],
        ]))->withQuery(new Query);

        $result = iterator_to_array($source->stream(0), false);

        $this->assertCount(3, $result);
    }

    public function test_stream_without_cursor_capability_warns_and_yields_first_page_only(): void
    {
        Log::spy();

        $source = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => $this->rows(1, 3)]]),
            capabilities: new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: false,
                cursor: false,
                mutate: false,
                stream: true,
            ),
        )->withQuery(new Query);

        $result = iterator_to_array($source->stream(2), false);

        $this->assertCount(3, $result);
        $this->assertCount(1, $this->calls, 'fetcher must be invoked once when cursor capability disabled');

        Log::shouldHaveReceived('warning')
            ->with('tables.source.http.cursor_required_for_stream', Mockery::any())
            ->once();
    }

    public function test_stream_terminates_when_next_cursor_null(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => [['id' => 1]], 'nextCursor' => 'c1'],
            ['rows' => [['id' => 2]], 'nextCursor' => null],
        ]))->withQuery(new Query);

        $result = iterator_to_array($source->stream(1), false);

        $this->assertCount(2, $result);
        $this->assertCount(2, $this->calls, 'stream terminates after the page with nextCursor=null without extra fetches');
    }

    public function test_stream_iteration_safety_cap_throws_logic_exception(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => [['id' => 1]], 'nextCursor' => 'never-null'],
        ]))->withQuery(new Query);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('safety cap of 10000 iterations');

        foreach ($source->stream(1) as $row) {
            // consume — guard ensures loop terminates if cap mis-fires
            if (count($this->calls) > 10_002) {
                $this->fail('safety cap of 10 000 iterations was not enforced');
            }
        }
    }
}
