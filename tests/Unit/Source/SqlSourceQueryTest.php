<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Source\SqlSource;
use Mercurio\Tables\Tests\TestCase;
use Mercurio\Tables\View\SavedView;
use Mockery;

final class SqlSourceQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('test_orders')->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'paid', 'total' => 200.00, 'customer' => 'Bob', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'number' => 'B-2001', 'status' => 'pending', 'total' => 300.00, 'customer' => 'Carol', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function source(): SqlSource
    {
        return SqlSource::for('test_orders');
    }

    private function sourceWithResource(ListResource $resource): SqlSource
    {
        return SqlSource::for('test_orders', null, 'id', null, $resource);
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
                return [
                    TextField::make('status'),
                ];
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
                return [
                    TextField::make('status'),
                ];
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
        $source = $this->source();
        $shaped = $source->withQuery(new Query);

        $this->assertNotSame($source, $shaped);
        $this->assertSame(3, $source->count());
    }

    public function test_apply_search_like_on_single_column(): void
    {
        $q = new Query;
        $q->search = 'A-100';
        $q->searchableColumns = ['number'];

        $this->assertSame(2, $this->source()->withQuery($q)->count());
    }

    public function test_apply_search_escapes_like_wildcards_via_discriminating_pattern(): void
    {
        // (a) Underscore must be escaped — without encoding `_` would be a single-char wildcard
        // and LIKE `%_%` would match all 3 rows. With encoding (bindings `%\_%`), SQLite without
        // ESCAPE clause looks for literal `\_` substring → no row contains it → count=0.
        $q1 = new Query;
        $q1->search = '_';
        $q1->searchableColumns = ['number'];
        $this->assertSame(
            0,
            $this->source()->withQuery($q1)->count(),
            'broken encoding would let `_` act as wildcard and match all 3 rows',
        );

        // (b) Percent must be escaped — without encoding `%` would be a 0+ char wildcard
        // and LIKE `%%%` would match everything. With encoding (bindings `%\%%`), SQLite
        // looks for literal `\%` substring → no row contains it → count=0.
        $q2 = new Query;
        $q2->search = '%';
        $q2->searchableColumns = ['number'];
        $this->assertSame(
            0,
            $this->source()->withQuery($q2)->count(),
            'broken encoding would let `%` act as wildcard and match all 3 rows',
        );
    }

    public function test_apply_search_noop_when_search_is_null(): void
    {
        $this->assertSame(3, $this->source()->withQuery(new Query)->count());
    }

    public function test_apply_search_noop_when_searchable_columns_empty(): void
    {
        $q = new Query;
        $q->search = 'X';
        $q->searchableColumns = [];

        $this->assertSame(3, $this->source()->withQuery($q)->count());
    }

    public function test_apply_search_warns_and_skips_dotted_columns(): void
    {
        Log::spy();

        $q = new Query;
        $q->search = 'X';
        $q->searchableColumns = ['customer.name'];

        $this->assertSame(3, $this->source()->withQuery($q)->count());

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.sql.search_dotted_unsupported',
                Mockery::on(static fn (array $ctx): bool => ($ctx['column'] ?? null) === 'customer.name'),
            )
            ->once();
    }

    public function test_apply_sort_asc(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'asc';

        $page = $this->source()->withQuery($q)->page(1, 10);
        $ids = array_map(static fn ($m): int => (int) $m->id, $page->rows);

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_sort_desc(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'desc';

        $page = $this->source()->withQuery($q)->page(1, 10);
        $ids = array_map(static fn ($m): int => (int) $m->id, $page->rows);

        $this->assertSame([3, 2, 1], $ids);
    }

    public function test_apply_sort_defaults_to_asc_for_unknown_direction(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'xxx';

        $page = $this->source()->withQuery($q)->page(1, 10);
        $ids = array_map(static fn ($m): int => (int) $m->id, $page->rows);

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_sort_noop_when_sort_field_is_null(): void
    {
        $page = $this->source()->withQuery(new Query)->page(1, 10);
        $ids = array_map(static fn ($m): int => (int) $m->id, $page->rows);

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_conditions_via_builtin_filter_when_no_resource(): void
    {
        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];

        $shaped = $this->source()->withQuery($q);

        $this->assertSame(1, $shaped->count());
        $this->assertSame(2, (int) $shaped->page(1, 10)->rows[0]->id);
    }

    public function test_apply_conditions_via_field_applier_when_resource_has_matching_field(): void
    {
        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'pending')];

        $shaped = $this->sourceWithResource($this->resourceWithStatusField())->withQuery($q);

        $this->assertSame(2, $shaped->count());
    }

    public function test_apply_qb_root_ignored_when_resource_is_null(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, []);

        $this->assertSame(3, $this->source()->withQuery($q)->count());
    }

    public function test_apply_qb_root_atom_group_with_resource(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [new AtomCondition('status', Operator::Eq, 'paid')]);

        $shaped = $this->sourceWithResource($this->resourceWithStatusField())->withQuery($q);

        $this->assertSame(1, $shaped->count());
    }

    public function test_apply_qb_with_resource_wraps_atom_condition_in_single_child_group(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomCondition('status', Operator::Eq, 'paid');

        $shaped = $this->sourceWithResource($this->resourceWithStatusField())->withQuery($q);

        $this->assertSame(1, $shaped->count());
    }

    public function test_saved_view_scope_warns_when_eloquent_scope_used(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'archived';

        $this->sourceWithResource($this->resourceWithSavedViewScope())->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.sql.saved_view_scope_unsupported',
                Mockery::on(static fn (array $ctx): bool => ($ctx['resource'] ?? null) === 'r' && ($ctx['view'] ?? null) === 'archived'),
            )
            ->once();
    }

    public function test_saved_view_with_conditions_does_not_warn(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'paid';

        $this->sourceWithResource($this->resourceWithSavedViewConditions())->withQuery($q);

        Log::shouldNotHaveReceived('warning', ['tables.source.sql.saved_view_scope_unsupported', Mockery::any()]);
    }
}
