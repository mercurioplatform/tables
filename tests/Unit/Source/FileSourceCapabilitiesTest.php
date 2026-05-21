<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\FileSource;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class FileSourceCapabilitiesTest extends TestCase
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

    private function writeTempCsv(string $content, string $suffix = '.csv'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mtables_csv_').$suffix;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function writeTempJsonl(string $content, string $suffix = '.jsonl'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mtables_jsonl_').$suffix;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_default_capabilities_in_materialized_mode(): void
    {
        $path = $this->writeTempCsv("id,name\n1,A\n");

        $caps = FileSource::csv($path)->capabilities();

        $expected = new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: true,
            cursor: false,
            mutate: false,
            stream: true,
            qbTree: true,
        );

        $this->assertEquals($expected, $caps);
    }

    public function test_default_capabilities_in_lazy_mode(): void
    {
        $path = $this->writeTempCsv("id,name\n1,A\n2,B\n3,C\n");

        $caps = FileSource::csv($path, materializeUnderBytes: 5)->capabilities();

        $expected = new Capabilities(
            filter: true,
            sort: false,
            search: true,
            count: false,
            cursor: false,
            mutate: false,
            stream: true,
            qbTree: false,
        );

        $this->assertEquals($expected, $caps);
    }

    public function test_for_factory_auto_detect_csv_by_extension(): void
    {
        $path = $this->writeTempCsv("id,name\n1,A\n", '.csv');

        $source = FileSource::for($path);

        $this->assertSame('csv', $source->format);
        $this->assertSame(1, $source->getRows()->count());
    }

    public function test_for_factory_auto_detect_jsonl_by_extension(): void
    {
        $path = $this->writeTempJsonl("{\"id\":1,\"name\":\"A\"}\n", '.jsonl');

        $source = FileSource::for($path);

        $this->assertSame('jsonl', $source->format);
        $this->assertSame(1, $source->getRows()->count());
    }

    public function test_for_factory_auto_detect_ndjson_by_extension(): void
    {
        $path = $this->writeTempJsonl("{\"id\":1}\n", '.ndjson');

        $source = FileSource::for($path);

        $this->assertSame('jsonl', $source->format);
        $this->assertSame(1, $source->getRows()->count());
    }

    public function test_for_factory_unknown_extension_throws_logic_exception(): void
    {
        $path = $this->writeTempCsv("anything\n", '.txt');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot auto-detect format');

        FileSource::for($path);
    }

    public function test_csv_factory_constructs_with_custom_delimiter_and_columns(): void
    {
        $path = $this->writeTempCsv("1;A\n2;B\n");

        $source = FileSource::csv($path, delimiter: ';', columns: ['a', 'b']);

        $rows = $source->getRows()->all();

        $this->assertSame([['a' => '1', 'b' => 'A'], ['a' => '2', 'b' => 'B']], $rows);
    }

    public function test_jsonl_factory_strict_json_default_true_throws_during_construction(): void
    {
        $path = $this->writeTempJsonl("{\"id\":1}\n{invalid\n");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('JsonlFileReader: invalid JSON on line');

        FileSource::jsonl($path);
    }

    public function test_jsonl_factory_strict_json_override_false_skips_invalid_lines(): void
    {
        Log::spy();

        $path = $this->writeTempJsonl("{\"id\":1}\n{invalid\n{\"id\":3}\n");

        $source = FileSource::jsonl($path, strictJson: false);

        $this->assertSame(2, $source->getRows()->count());

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.read.invalid_json_line', Mockery::any())
            ->once();
    }

    public function test_ndjson_is_alias_for_jsonl(): void
    {
        $path = $this->writeTempJsonl("{\"id\":1}\n", '.ndjson');

        $viaNdjson = FileSource::ndjson($path);
        $viaJsonl = FileSource::jsonl($path);

        $this->assertEquals($viaNdjson->capabilities(), $viaJsonl->capabilities());
        $this->assertSame('jsonl', $viaNdjson->format);
        $this->assertSame('jsonl', $viaJsonl->format);
    }

    public function test_capabilities_clamping_lazy_mode_user_passes_sort_true(): void
    {
        Log::spy();

        $path = $this->writeTempCsv("id,name\n1,A\n2,B\n");

        $caps = (new FileSource(
            path: $path,
            format: 'csv',
            capabilities: new Capabilities(sort: true),
            materializeUnderBytes: 5,
        ))->capabilities();

        $this->assertFalse($caps->sort);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.sort_unsupported_in_lazy_mode', Mockery::any())
            ->once();
    }

    public function test_capabilities_clamping_mutate_true_warns_in_materialized_mode(): void
    {
        Log::spy();

        $path = $this->writeTempCsv("id,name\n1,A\n");

        $caps = (new FileSource(
            path: $path,
            format: 'csv',
            capabilities: new Capabilities(mutate: true),
        ))->capabilities();

        $this->assertFalse($caps->mutate);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.mutate_capability_clamped', Mockery::any())
            ->once();
    }

    public function test_capabilities_clamping_mutate_true_warns_in_lazy_mode(): void
    {
        Log::spy();

        $path = $this->writeTempCsv("id,name\n1,A\n2,B\n");

        $caps = (new FileSource(
            path: $path,
            format: 'csv',
            capabilities: new Capabilities(mutate: true),
            materializeUnderBytes: 5,
        ))->capabilities();

        $this->assertFalse($caps->mutate);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.mutate_capability_clamped', Mockery::any())
            ->once();
    }

    public function test_invalid_path_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('path is not a readable file');

        FileSource::csv('/no/such/path/should/exist.csv');
    }

    public function test_probe_returns_null(): void
    {
        $path = $this->writeTempCsv("id,name\n1,A\n");

        $this->assertNull(FileSource::csv($path)->probe());
    }

    public function test_oversized_materialize_under_bytes_clamps_with_warning(): void
    {
        Log::spy();

        $path = $this->writeTempCsv("id,name\n1,A\n");

        $source = new FileSource(
            path: $path,
            format: 'csv',
            materializeUnderBytes: 100_000_000,
        );

        $this->assertSame(1, $source->getRows()->count());

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.file_too_large_for_materialize', Mockery::any())
            ->once();
    }
}
