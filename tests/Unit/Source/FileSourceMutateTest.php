<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\FileSource;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class FileSourceMutateTest extends TestCase
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

    private function smallCsv(): string
    {
        return $this->writeTempCsv("id,name\n1,A\n2,B\n3,C\n");
    }

    public function test_update_throws_logic_exception_by_default(): void
    {
        $source = FileSource::csv($this->smallCsv());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FileSource is read-only by design');

        $source->update(1, ['status' => 'paid']);
    }

    public function test_update_logs_warning_with_resource_path_id_and_columns(): void
    {
        Log::spy();

        $path = $this->smallCsv();
        $expectedBasename = basename($path);

        try {
            FileSource::csv($path)->update(1, ['status' => 'paid']);
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
        }

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.file.mutate_denied',
                Mockery::on(static fn (array $ctx): bool => array_key_exists('resource', $ctx)
                    && $ctx['resource'] === null
                    && ($ctx['id'] ?? null) === 1
                    && ($ctx['columns'] ?? null) === ['status']
                    && ($ctx['path'] ?? null) === $expectedBasename),
            )
            ->once();
    }

    public function test_update_with_mutate_capability_clamped_to_false_in_ctor(): void
    {
        Log::spy();

        $source = new FileSource(
            path: $this->smallCsv(),
            format: 'csv',
            capabilities: new Capabilities(mutate: true),
        );

        $this->assertFalse($source->capabilities()->mutate);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.mutate_capability_clamped', Mockery::any())
            ->once();
    }

    public function test_get_rows_returns_collection_in_materialized_mode(): void
    {
        $source = FileSource::csv($this->smallCsv());

        $rows = $source->getRows();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertSame(3, $rows->count());
    }

    public function test_get_rows_throws_logic_exception_in_lazy_mode(): void
    {
        Log::spy();

        $source = FileSource::csv($this->smallCsv(), materializeUnderBytes: 5);

        try {
            $source->getRows();
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('FileSource::getRows() unavailable in lazy mode', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.get_rows_unavailable_in_lazy_mode', Mockery::any())
            ->once();
    }
}
