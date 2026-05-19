<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;
use Mercurio\Tables\View\SavedView;
use Mockery;

final class ArraySourceQueryTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100, 'customer' => 'Alice'],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'paid', 'total' => 200, 'customer' => 'Bob'],
            ['id' => 3, 'number' => 'B-2001', 'status' => 'pending', 'total' => 300, 'customer' => 'Carol'],
        ];
    }

    /**
     * Ad-hoc ListResource с одним field `status`, кастомизированным через filterUsing.
     * Используется в тестах 19 и 20 для проверки `field_filter_customization_skipped` WARN.
     */
    private function resourceWithCustomFilter(): ListResource
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
                    TextField::make('status')->filterUsing(fn () => null),
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
        $source = new ArraySource($this->rows());
        $shaped = $source->withQuery(new Query);

        $this->assertNotSame($source, $shaped);
        $this->assertSame(3, $source->count());
    }

    public function test_apply_search_substring_match(): void
    {
        $q = new Query;
        $q->search = 'B-2';
        $q->searchableColumns = ['number'];

        $shaped = (new ArraySource($this->rows()))->withQuery($q);

        $this->assertSame(1, $shaped->count());
        $page = $shaped->page(1, 10);
        $this->assertSame(3, $page->rows[0]['id']);
    }

    public function test_apply_search_is_case_insensitive_utf8(): void
    {
        $rows = [
            ['id' => 1, 'customer' => 'Anna'],
            ['id' => 2, 'customer' => 'Анна'],
        ];

        $q = new Query;
        $q->search = 'anna';
        $q->searchableColumns = ['customer'];

        $shaped = (new ArraySource($rows))->withQuery($q);
        $this->assertSame(1, $shaped->count());
        $this->assertSame(1, $shaped->page(1, 10)->rows[0]['id']);

        $q2 = new Query;
        $q2->search = 'анна';
        $q2->searchableColumns = ['customer'];
        $shaped2 = (new ArraySource($rows))->withQuery($q2);
        $this->assertSame(1, $shaped2->count());
        $this->assertSame(2, $shaped2->page(1, 10)->rows[0]['id']);
    }

    public function test_apply_search_skips_rows_with_null_values(): void
    {
        $rows = [
            ['id' => 1, 'number' => 'X', 'customer' => 'A'],
            ['id' => 2, 'number' => null, 'customer' => 'X'],
        ];

        $q = new Query;
        $q->search = 'X';
        $q->searchableColumns = ['number'];

        $shaped = (new ArraySource($rows))->withQuery($q);

        $this->assertSame(1, $shaped->count());
        $this->assertSame(1, $shaped->page(1, 10)->rows[0]['id']);
    }

    public function test_apply_search_noop_when_search_is_null(): void
    {
        $shaped = (new ArraySource($this->rows()))->withQuery(new Query);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_search_noop_when_searchable_columns_empty(): void
    {
        $q = new Query;
        $q->search = 'A-1';
        $q->searchableColumns = [];

        $shaped = (new ArraySource($this->rows()))->withQuery($q);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_sort_asc(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'asc';

        $page = (new ArraySource($this->rows()))->withQuery($q)->page(1, 10);

        $ids = array_map(static fn (array $r): int => $r['id'], $page->rows);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_sort_desc(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'desc';

        $page = (new ArraySource($this->rows()))->withQuery($q)->page(1, 10);

        $ids = array_map(static fn (array $r): int => $r['id'], $page->rows);
        $this->assertSame([3, 2, 1], $ids);
    }

    public function test_apply_sort_noop_when_sort_field_is_null(): void
    {
        $page = (new ArraySource($this->rows()))->withQuery(new Query)->page(1, 10);

        $ids = array_map(static fn (array $r): int => $r['id'], $page->rows);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_sort_noop_on_empty_rows(): void
    {
        $q = new Query;
        $q->sortField = 'total';

        $this->assertSame(0, (new ArraySource([]))->withQuery($q)->count());
    }

    public function test_apply_sort_warns_and_skips_when_field_is_unknown(): void
    {
        Log::spy();

        $q = new Query;
        $q->sortField = 'nonexistent_field';

        $page = (new ArraySource($this->rows()))->withQuery($q)->page(1, 10);

        $ids = array_map(static fn (array $r): int => $r['id'], $page->rows);
        $this->assertSame([1, 2, 3], $ids);

        Log::shouldHaveReceived('warning')
            ->with('tables.array_source.sort_unknown_field', Mockery::on(static fn (array $ctx): bool => ($ctx['field'] ?? null) === 'nonexistent_field'))
            ->once();
    }

    public function test_apply_conditions_via_builtin_evaluator(): void
    {
        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];

        $shaped = (new ArraySource($this->rows()))->withQuery($q);

        $this->assertSame(1, $shaped->count());
        $this->assertSame(2, $shaped->page(1, 10)->rows[0]['id']);
    }

    public function test_apply_conditions_combines_with_an_d_semantics(): void
    {
        $q = new Query;
        $q->conditions = [
            new FilterCondition('status', Operator::Eq, 'pending'),
            new FilterCondition('total', Operator::Gte, 200),
        ];

        $shaped = (new ArraySource($this->rows()))->withQuery($q);

        $this->assertSame(1, $shaped->count());
        $this->assertSame(3, $shaped->page(1, 10)->rows[0]['id']);
    }

    public function test_apply_conditions_without_resource_does_not_warn(): void
    {
        Log::spy();

        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];

        (new ArraySource($this->rows()))->withQuery($q);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_apply_qb_root_atom_group_and(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [new AtomCondition('status', Operator::Eq, 'paid')]);

        $shaped = (new ArraySource($this->rows()))->withQuery($q);

        $this->assertSame(1, $shaped->count());
        $this->assertSame(2, $shaped->page(1, 10)->rows[0]['id']);
    }

    public function test_apply_qb_root_atom_group_or(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomGroup('OR', false, [
            new AtomCondition('id', Operator::Eq, 1),
            new AtomCondition('id', Operator::Eq, 3),
        ]);

        $shaped = (new ArraySource($this->rows()))->withQuery($q);

        $this->assertSame(2, $shaped->count());
        $ids = array_map(static fn (array $r): int => $r['id'], $shaped->page(1, 10)->rows);
        $this->assertSame([1, 3], $ids);
    }

    public function test_saved_view_scope_warns_when_eloquent_scope_used(): void
    {
        $resource = new class extends ListResource
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

        $source = new ArraySource($this->rows(), null, 'id', $resource);

        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'archived';
        $source->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.array_source.saved_view_scope_unsupported',
                Mockery::on(static fn (array $ctx): bool => ($ctx['resource'] ?? null) === 'r' && ($ctx['view'] ?? null) === 'archived'),
            )
            ->once();
    }

    public function test_saved_view_with_conditions_does_not_warn(): void
    {
        $resource = new TestOrdersResource;
        $source = new ArraySource(TestOrdersResource::rows(), null, 'id', $resource);

        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'paid';
        $shaped = $source->withQuery($q);

        Log::shouldNotHaveReceived('warning', ['tables.array_source.saved_view_scope_unsupported', Mockery::any()]);

        // No-op behaviour: ArraySource::guardSavedViewScope только пишет WARN.
        // Реальный merge SavedView::conditions[] в Query.conditions делает
        // FilterPipeline ПЕРЕД withQuery() (positive end-to-end проверяется в
        // tests/Feature/JsonApi/ArraySourceApiTest::test_saved_view_paid).
        $this->assertSame(4, $shaped->count());
    }

    public function test_apply_conditions_warns_when_field_has_custom_filter_callback(): void
    {
        $resource = $this->resourceWithCustomFilter();
        $source = new ArraySource($this->rows(), null, 'id', $resource);

        Log::spy();

        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];
        $shaped = $source->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.array_source.field_filter_customization_skipped',
                Mockery::on(static fn (array $ctx): bool => ($ctx['field'] ?? null) === 'status' && ! array_key_exists('context', $ctx)),
            )
            ->once();

        $this->assertSame(1, $shaped->count());
        $this->assertSame(2, $shaped->page(1, 10)->rows[0]['id']);
    }

    public function test_apply_qb_root_warns_when_atom_field_has_custom_filter_callback(): void
    {
        $resource = $this->resourceWithCustomFilter();
        $source = new ArraySource($this->rows(), null, 'id', $resource);

        Log::spy();

        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [new AtomCondition('status', Operator::Eq, 'paid')]);
        $shaped = $source->withQuery($q);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.array_source.field_filter_customization_skipped',
                Mockery::on(static fn (array $ctx): bool => ($ctx['field'] ?? null) === 'status' && ($ctx['context'] ?? null) === 'qb'),
            )
            ->once();

        $this->assertSame(1, $shaped->count());
        $this->assertSame(2, $shaped->page(1, 10)->rows[0]['id']);
    }
}
