<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Source\FileSource;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class FileSourceFindTest extends TestCase
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

    private function seedCsvWithIds(int $n): string
    {
        $lines = "id,name\n";
        for ($i = 1; $i <= $n; $i++) {
            $lines .= "{$i},Item #{$i}\n";
        }

        return $this->writeTempCsv($lines);
    }

    public function test_find_returns_row_for_existing_id_materialized(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3));

        $row = $source->find(2);

        $this->assertIsArray($row);
        $this->assertSame('2', $row['id']);
        $this->assertSame('Item #2', $row['name']);
    }

    public function test_find_returns_null_for_missing_id_materialized(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3));

        $this->assertNull($source->find(999));
    }

    public function test_find_returns_row_for_existing_id_lazy(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3), materializeUnderBytes: 5);

        $row = $source->find(2);

        $this->assertIsArray($row);
        $this->assertSame('2', $row['id']);
    }

    public function test_find_returns_null_for_missing_id_lazy(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3), materializeUnderBytes: 5);

        $this->assertNull($source->find(999));
    }

    public function test_find_materialized_warns_on_linear_scan_above_threshold(): void
    {
        Log::spy();

        $source = FileSource::csv($this->seedCsvWithIds(10_001));

        $source->find(9999);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.file.find.linear_scan',
                Mockery::on(static fn (array $ctx): bool => ($ctx['size'] ?? 0) > 10_000),
            )
            ->once();
    }

    public function test_find_many_empty_array_returns_empty(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3));

        $this->assertSame([], $source->findMany([]));
    }

    public function test_find_many_returns_matched_rows_materialized(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3));

        $rows = $source->findMany([1, 3]);

        $this->assertCount(2, $rows);
    }

    public function test_find_many_skips_missing_ids_materialized(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3));

        $rows = $source->findMany([1, 999]);

        $this->assertCount(1, $rows);
    }

    public function test_find_many_returns_rows_without_order_guarantee(): void
    {
        $source = FileSource::csv($this->seedCsvWithIds(3));

        $rowsIterable = $source->findMany([3, 1, 2]);
        $rows = is_array($rowsIterable) ? $rowsIterable : iterator_to_array($rowsIterable, false);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        sort($ids);

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_find_lazy_mode_cumulative_warning_after_threshold(): void
    {
        Log::spy();

        $source = FileSource::csv($this->seedCsvWithIds(1000), materializeUnderBytes: 5);

        for ($i = 0; $i < 101; $i++) {
            $source->find('nonexistent_'.$i);
        }

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.find_linear_scan_in_lazy_mode', Mockery::any())
            ->once();
    }
}
