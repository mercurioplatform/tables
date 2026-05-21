<?php

namespace Mercurio\Tables\Tests\Unit\Source\Support;

use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\Support\CsvFileReader;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class CsvFileReaderTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'mtables_csv_reader_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_read_reads_header_from_first_line_when_columns_null(): void
    {
        $path = $this->writeTempFile("id,name\n1,A\n2,B\n");

        $rows = iterator_to_array((new CsvFileReader)->read($path), false);

        $this->assertSame([
            ['id' => '1', 'name' => 'A'],
            ['id' => '2', 'name' => 'B'],
        ], $rows);
    }

    public function test_read_uses_passed_columns_when_provided(): void
    {
        $path = $this->writeTempFile("1,A\n2,B\n");

        $rows = iterator_to_array((new CsvFileReader(columns: ['x', 'y']))->read($path), false);

        $this->assertSame([
            ['x' => '1', 'y' => 'A'],
            ['x' => '2', 'y' => 'B'],
        ], $rows);
    }

    public function test_read_strips_bom_from_first_header_cell(): void
    {
        $path = $this->writeTempFile("\xEF\xBB\xBFid,name\n1,A\n");

        $rows = iterator_to_array((new CsvFileReader)->read($path), false);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertSame('1', $rows[0]['id']);
    }

    public function test_read_skips_empty_lines_silently(): void
    {
        $path = $this->writeTempFile("id,name\n1,A\n\n2,B\n");

        $rows = iterator_to_array((new CsvFileReader)->read($path), false);

        $this->assertCount(2, $rows);
    }

    public function test_read_warns_and_skips_on_column_count_mismatch(): void
    {
        Log::spy();

        $path = $this->writeTempFile("id,name\n1,A,extra\n2,B\n");

        $rows = iterator_to_array((new CsvFileReader)->read($path), false);

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => '2', 'name' => 'B'], $rows[0]);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.read.column_count_mismatch', Mockery::any())
            ->once();
    }

    public function test_read_custom_delimiter(): void
    {
        $path = $this->writeTempFile("id;name\n1;A\n");

        $rows = iterator_to_array((new CsvFileReader(delimiter: ';'))->read($path), false);

        $this->assertSame([['id' => '1', 'name' => 'A']], $rows);
    }

    public function test_read_custom_enclosure(): void
    {
        $path = $this->writeTempFile("id,name\n1,'hello, world'\n");

        $rows = iterator_to_array((new CsvFileReader(enclosure: "'"))->read($path), false);

        $this->assertSame('hello, world', $rows[0]['name']);
    }

    public function test_read_throws_logic_exception_when_file_not_readable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CsvFileReader: failed to open file');

        iterator_to_array((new CsvFileReader)->read('/no/such/file/path.csv'), false);
    }
}
