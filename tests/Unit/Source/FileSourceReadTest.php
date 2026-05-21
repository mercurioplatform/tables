<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Pagination\LengthAwarePaginator;
use Mercurio\Tables\Source\FileSource;
use Mercurio\Tables\Tests\TestCase;

final class FileSourceReadTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function writeTempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mtables_csv_').'.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function writeTempJsonl(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mtables_jsonl_').'.jsonl';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function seedCsv(int $rows): string
    {
        $lines = "id,name\n";
        for ($i = 1; $i <= $rows; $i++) {
            $lines .= "{$i},r{$i}\n";
        }

        return $this->writeTempCsv($lines);
    }

    public function test_count_materialized_returns_total_rows(): void
    {
        $path = $this->seedCsv(5);

        $this->assertSame(5, FileSource::csv($path)->count());
    }

    public function test_count_on_empty_csv_returns_zero(): void
    {
        $path = $this->writeTempCsv("id,name\n");

        $this->assertSame(0, FileSource::csv($path)->count());
    }

    public function test_count_lazy_returns_null(): void
    {
        $path = $this->seedCsv(5);

        $this->assertNull(FileSource::csv($path, materializeUnderBytes: 5)->count());
    }

    public function test_page_materialized_first_page_returns_first_n_rows(): void
    {
        $path = $this->seedCsv(5);

        $page = FileSource::csv($path)->page(1, 2);

        $this->assertCount(2, $page->rows);
        $this->assertSame(5, $page->total);
        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['id'], $page->rows));
        $this->assertInstanceOf(LengthAwarePaginator::class, $page->delegate);
    }

    public function test_page_materialized_second_page_offset_pagination(): void
    {
        $path = $this->seedCsv(5);

        $page = FileSource::csv($path)->page(2, 2);

        $this->assertCount(2, $page->rows);
        $this->assertSame([3, 4], array_map(static fn (array $r): int => (int) $r['id'], $page->rows));
    }

    public function test_page_materialized_beyond_last_returns_empty_rows_but_correct_total(): void
    {
        $path = $this->seedCsv(5);

        $page = FileSource::csv($path)->page(99, 10);

        $this->assertSame([], $page->rows);
        $this->assertSame(5, $page->total);
    }

    public function test_page_lazy_first_page_returns_first_n_rows(): void
    {
        $path = $this->seedCsv(5);

        $page = FileSource::csv($path, materializeUnderBytes: 5)->page(1, 2);

        $this->assertCount(2, $page->rows);
        $this->assertNull($page->total);
        $this->assertNull($page->delegate);
        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['id'], $page->rows));
    }

    public function test_page_lazy_second_page_via_reader_skip_take(): void
    {
        $path = $this->seedCsv(5);

        $page = FileSource::csv($path, materializeUnderBytes: 5)->page(2, 2);

        $this->assertCount(2, $page->rows);
        $this->assertSame([3, 4], array_map(static fn (array $r): int => (int) $r['id'], $page->rows));
    }

    public function test_page_clamps_page_below_one(): void
    {
        $path = $this->seedCsv(3);

        $page = FileSource::csv($path)->page(0, 10);

        $this->assertSame(1, $page->page);
    }

    public function test_page_clamps_per_page_below_one(): void
    {
        $path = $this->seedCsv(3);

        $page = FileSource::csv($path)->page(1, 0);

        $this->assertSame(1, $page->perPage);
        $this->assertCount(1, $page->rows);
    }

    public function test_stream_materialized_yields_all_rows_in_chunks(): void
    {
        $path = $this->seedCsv(5);

        $rows = iterator_to_array(FileSource::csv($path)->stream(2), false);

        $this->assertCount(5, $rows);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    public function test_stream_on_empty_csv_yields_nothing(): void
    {
        $path = $this->writeTempCsv("id,name\n");

        $rows = iterator_to_array(FileSource::csv($path)->stream(2), false);

        $this->assertSame([], $rows);
    }

    public function test_stream_lazy_yields_all_rows(): void
    {
        $path = $this->seedCsv(5);

        $rows = iterator_to_array(FileSource::csv($path, materializeUnderBytes: 5)->stream(2), false);

        $this->assertCount(5, $rows);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    public function test_stream_clamps_chunk_size_below_one_to_one(): void
    {
        $path = $this->seedCsv(3);

        $rows = iterator_to_array(FileSource::csv($path)->stream(0), false);

        $this->assertCount(3, $rows);
    }

    public function test_jsonl_count_and_page_yield_correct_rows(): void
    {
        $path = $this->writeTempJsonl("{\"id\":1,\"v\":\"A\"}\n{\"id\":2,\"v\":\"B\"}\n{\"id\":3,\"v\":\"C\"}\n");

        $source = FileSource::jsonl($path);

        $this->assertSame(3, $source->count());

        $page = $source->page(1, 10);

        $this->assertSame('A', $page->rows[0]['v']);
        $this->assertSame('C', $page->rows[2]['v']);
    }
}
