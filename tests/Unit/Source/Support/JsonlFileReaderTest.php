<?php

namespace Mercurio\Tables\Tests\Unit\Source\Support;

use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\Support\JsonlFileReader;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class JsonlFileReaderTest extends TestCase
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

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mtables_jsonl_reader_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_read_yields_decoded_objects_per_line(): void
    {
        $path = $this->writeTempFile("{\"id\":1,\"v\":\"A\"}\n{\"id\":2,\"v\":\"B\"}\n");

        $rows = iterator_to_array((new JsonlFileReader)->read($path), false);

        $this->assertSame([
            ['id' => 1, 'v' => 'A'],
            ['id' => 2, 'v' => 'B'],
        ], $rows);
    }

    public function test_read_strips_bom_from_first_line(): void
    {
        $path = $this->writeTempFile("\xEF\xBB\xBF{\"id\":1}\n");

        $rows = iterator_to_array((new JsonlFileReader)->read($path), false);

        $this->assertSame([['id' => 1]], $rows);
    }

    public function test_read_skips_blank_lines_silently(): void
    {
        $path = $this->writeTempFile("{\"id\":1}\n\n  \n{\"id\":2}\n");

        $rows = iterator_to_array((new JsonlFileReader)->read($path), false);

        $this->assertCount(2, $rows);
    }

    public function test_read_strict_mode_throws_logic_exception_on_invalid_json(): void
    {
        $path = $this->writeTempFile("{\"id\":1}\n{invalid\n");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('JsonlFileReader: invalid JSON on line 2');

        iterator_to_array((new JsonlFileReader)->read($path), false);
    }

    public function test_read_non_strict_mode_warns_and_skips_invalid_json(): void
    {
        Log::spy();

        $path = $this->writeTempFile("{\"id\":1}\n{invalid\n{\"id\":3}\n");

        $rows = iterator_to_array((new JsonlFileReader(strictJson: false))->read($path), false);

        $this->assertCount(2, $rows);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.read.invalid_json_line', Mockery::any())
            ->once();
    }

    public function test_read_warns_and_skips_non_array_decoded_value_primitive(): void
    {
        Log::spy();

        $path = $this->writeTempFile("123\n{\"id\":2}\n");

        $rows = iterator_to_array((new JsonlFileReader)->read($path), false);

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 2], $rows[0]);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.file.read.invalid_json_line',
                Mockery::on(static fn (array $ctx): bool => str_contains((string) ($ctx['reason'] ?? ''), 'non-array')),
            )
            ->once();
    }

    public function test_read_warns_and_skips_non_array_decoded_value_null(): void
    {
        Log::spy();

        $path = $this->writeTempFile("null\n{\"id\":2}\n");

        $rows = iterator_to_array((new JsonlFileReader)->read($path), false);

        $this->assertCount(1, $rows);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.read.invalid_json_line', Mockery::any())
            ->once();
    }

    public function test_read_warns_and_skips_sequential_list_array(): void
    {
        Log::spy();

        $path = $this->writeTempFile("[1,2,3]\n{\"id\":4}\n");

        $rows = iterator_to_array((new JsonlFileReader)->read($path), false);

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 4], $rows[0]);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.file.read.invalid_json_line',
                Mockery::on(static fn (array $ctx): bool => ($ctx['reason'] ?? null) === 'expected JSON object (associative), got list'),
            )
            ->once();
    }

    public function test_read_throws_logic_exception_when_file_not_readable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('JsonlFileReader: failed to open file');

        iterator_to_array((new JsonlFileReader)->read('/no/such/file/path.jsonl'), false);
    }
}
