<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\FileSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Tests\TestCase;
use Mercurio\Tables\View\SavedView;
use Mockery;

final class FileSourceQueryTest extends TestCase
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

    private function ordersCsv(): string
    {
        return $this->writeTempCsv(
            "id,status,total,customer\n"
            ."1,pending,100,Alice\n"
            ."2,paid,200,Bob\n"
            ."3,pending,300,Carol\n"
        );
    }

    private function resourceWithStatusField(): ListResource
    {
        return new class extends ListResource
        {
            public function key(): string
            {
                return 'r';
            }

            public function fields(): array
            {
                return [TextField::make('status')->filterUsing(fn () => null)];
            }

            public function source(): ?Source
            {
                return null;
            }
        };
    }

    private function resourceWithSavedViewScope(): ListResource
    {
        return new class extends ListResource
        {
            public function key(): string
            {
                return 'r';
            }

            public function fields(): array
            {
                return [];
            }

            public function savedViews(): array
            {
                return [SavedView::scope('archived', 'Archive', 'archived')];
            }

            public function source(): ?Source
            {
                return null;
            }
        };
    }

    private function resourceWithSavedViewConditions(): ListResource
    {
        return new class extends ListResource
        {
            public function key(): string
            {
                return 'r';
            }

            public function fields(): array
            {
                return [];
            }

            public function savedViews(): array
            {
                return [
                    SavedView::conditions('paid', 'Paid', [
                        new FilterCondition('status', Operator::Eq, 'paid'),
                    ]),
                ];
            }

            public function source(): ?Source
            {
                return null;
            }
        };
    }

    public function test_with_query_returns_new_instance_and_does_not_mutate_original(): void
    {
        $source = FileSource::csv($this->ordersCsv());
        $shaped = $source->withQuery(new Query);

        $this->assertNotSame($source, $shaped);
        $this->assertSame(3, $source->count());
    }

    public function test_apply_search_substring_match_via_mb_stripos(): void
    {
        $q = new Query;
        $q->search = 'pe';
        $q->searchableColumns = ['status'];

        $shaped = FileSource::csv($this->ordersCsv())->withQuery($q);

        $this->assertSame(2, $shaped->count());
    }

    public function test_apply_search_noop_when_search_is_null(): void
    {
        $shaped = FileSource::csv($this->ordersCsv())->withQuery(new Query);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_search_noop_when_searchable_columns_empty(): void
    {
        $q = new Query;
        $q->search = 'X';
        $q->searchableColumns = [];

        $shaped = FileSource::csv($this->ordersCsv())->withQuery($q);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_conditions_builtin_filter_evaluator(): void
    {
        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];

        $shaped = FileSource::csv($this->ordersCsv())->withQuery($q);

        $this->assertSame(1, $shaped->count());
    }

    public function test_apply_conditions_with_field_customization_warns_and_skips(): void
    {
        Log::spy();

        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];

        $shaped = FileSource::csv($this->ordersCsv(), resource: $this->resourceWithStatusField())->withQuery($q);

        $this->assertSame(1, $shaped->count());

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.file.field_filter_customization_skipped',
                Mockery::on(static fn (array $ctx): bool => ($ctx['field'] ?? null) === 'status'
                    && ! array_key_exists('context', $ctx)),
            )
            ->once();
    }

    public function test_apply_qb_root_atom_group_in_materialized_mode(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [
            new AtomCondition('status', Operator::Eq, 'paid'),
        ]);

        $shaped = FileSource::csv($this->ordersCsv())->withQuery($q);

        $this->assertSame(1, $shaped->count());
    }

    public function test_apply_qb_root_lazy_mode_warns_and_nullifies(): void
    {
        Log::spy();

        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [
            new AtomCondition('status', Operator::Eq, 'paid'),
        ]);

        $shaped = FileSource::csv($this->ordersCsv(), materializeUnderBytes: 5)->withQuery($q);

        $this->assertNull($shaped->count());

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.qb_unsupported_in_lazy_mode', Mockery::any())
            ->once();
    }

    public function test_apply_sort_asc_in_materialized_mode(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'asc';

        $page = FileSource::csv($this->ordersCsv())->withQuery($q)->page(1, 10);

        $totals = array_map(static fn (array $r): int => (int) $r['total'], $page->rows);

        $this->assertSame([100, 200, 300], $totals);
    }

    public function test_apply_sort_desc_in_materialized_mode(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'desc';

        $page = FileSource::csv($this->ordersCsv())->withQuery($q)->page(1, 10);

        $totals = array_map(static fn (array $r): int => (int) $r['total'], $page->rows);

        $this->assertSame([300, 200, 100], $totals);
    }

    public function test_apply_sort_lazy_mode_warns_and_nullifies(): void
    {
        Log::spy();

        $q = new Query;
        $q->sortField = 'total';

        FileSource::csv($this->ordersCsv(), materializeUnderBytes: 5)->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.sort_unsupported_in_lazy_mode', Mockery::any())
            ->once();
    }

    public function test_apply_sort_unknown_field_warns_via_sample_heuristic(): void
    {
        Log::spy();

        $q = new Query;
        $q->sortField = 'nonexistent';

        FileSource::csv($this->ordersCsv())->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.sort_unknown_field', Mockery::any())
            ->once();
    }

    public function test_saved_view_scope_warns_for_eloquent_scope(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'archived';

        FileSource::csv($this->ordersCsv(), resource: $this->resourceWithSavedViewScope())->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.file.saved_view_scope_unsupported', Mockery::any())
            ->once();
    }

    public function test_saved_view_conditions_does_not_warn(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'paid';

        FileSource::csv($this->ordersCsv(), resource: $this->resourceWithSavedViewConditions())->withQuery($q);

        Log::shouldNotHaveReceived('warning', ['tables.source.file.saved_view_scope_unsupported', Mockery::any()]);
    }

    public function test_qb_root_field_customization_warns_qb_context(): void
    {
        Log::spy();

        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [
            new AtomCondition('status', Operator::Eq, 'paid'),
        ]);

        FileSource::csv($this->ordersCsv(), resource: $this->resourceWithStatusField())->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.file.field_filter_customization_skipped',
                Mockery::on(static fn (array $ctx): bool => ($ctx['field'] ?? null) === 'status'
                    && ($ctx['context'] ?? null) === 'qb'),
            )
            ->once();
    }
}
