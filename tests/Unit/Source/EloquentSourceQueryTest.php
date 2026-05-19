<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Tests\Fixtures\JsonApi\MutableOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class EloquentSourceQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestOrder::query()->insert([
            ['id' => 1, 'number' => 'A-1001', 'status' => 'pending', 'total' => 100.00, 'customer' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'paid', 'total' => 200.00, 'customer' => 'Bob', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'number' => 'B-2001', 'status' => 'pending', 'total' => 300.00, 'customer' => 'Carol', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_with_query_returns_new_instance_and_does_not_mutate_original(): void
    {
        $source = new EloquentSource(TestOrder::query());
        $shaped = $source->withQuery(new Query);

        $this->assertNotSame($source, $shaped);
        $this->assertSame(3, $source->count());
    }

    public function test_apply_search_like_on_single_column(): void
    {
        $q = new Query;
        $q->search = 'A-100';
        $q->searchableColumns = ['number'];

        $shaped = (new EloquentSource(TestOrder::query()))->withQuery($q);
        $page = $shaped->page(1, 10);

        $this->assertCount(2, $page->rows);
        $numbers = array_map(static fn (TestOrder $o): string => (string) $o->number, $page->rows);
        sort($numbers);
        $this->assertSame(['A-1001', 'A-1002'], $numbers);
    }

    public function test_apply_search_escapes_like_wildcards_in_pattern_encoding(): void
    {
        $q = new Query;
        $q->search = '%_X';
        $q->searchableColumns = ['number'];

        $shaped = (new EloquentSource(TestOrder::query()))->withQuery($q);
        $builder = $shaped->getBuilder();

        $this->assertStringContainsStringIgnoringCase('like ?', $builder->toSql());

        $bindings = $builder->getBindings();
        $this->assertContains('%\\%\\_X%', $bindings, 'search-term must be encoded with backslash-escaped % and _');
    }

    public function test_apply_search_noop_when_search_is_null(): void
    {
        $shaped = (new EloquentSource(TestOrder::query()))->withQuery(new Query);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_search_noop_when_searchable_columns_empty(): void
    {
        $q = new Query;
        $q->search = 'A-100';
        $q->searchableColumns = [];

        $shaped = (new EloquentSource(TestOrder::query()))->withQuery($q);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_sort_asc(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'asc';

        $page = (new EloquentSource(TestOrder::query()))->withQuery($q)->page(1, 10);

        $ids = array_map(static fn (TestOrder $o): int => (int) $o->id, $page->rows);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_sort_desc(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'desc';

        $page = (new EloquentSource(TestOrder::query()))->withQuery($q)->page(1, 10);

        $ids = array_map(static fn (TestOrder $o): int => (int) $o->id, $page->rows);
        $this->assertSame([3, 2, 1], $ids);
    }

    public function test_apply_sort_defaults_to_asc_for_unknown_direction(): void
    {
        $q = new Query;
        $q->sortField = 'total';
        $q->sortDirection = 'xxx';

        $page = (new EloquentSource(TestOrder::query()))->withQuery($q)->page(1, 10);

        $ids = array_map(static fn (TestOrder $o): int => (int) $o->id, $page->rows);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_sort_noop_when_sort_field_is_null(): void
    {
        $page = (new EloquentSource(TestOrder::query()))->withQuery(new Query)->page(1, 10);

        $ids = array_map(static fn (TestOrder $o): int => (int) $o->id, $page->rows);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_apply_conditions_via_builtin_filter(): void
    {
        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'pending')];

        $shaped = (new EloquentSource(TestOrder::query()))->withQuery($q);

        $this->assertSame(2, $shaped->count());
        $statuses = array_map(
            static fn (TestOrder $o): string => (string) $o->status,
            $shaped->page(1, 10)->rows,
        );
        $this->assertSame(['pending', 'pending'], $statuses);
    }

    public function test_qb_root_ignored_when_resource_is_null(): void
    {
        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, []);

        $shaped = (new EloquentSource(TestOrder::query()))->withQuery($q);

        $this->assertSame(3, $shaped->count());
    }

    public function test_saved_view_ignored_when_resource_is_null(): void
    {
        $q = new Query;
        $q->savedViewKey = 'nonexistent';

        $shaped = (new EloquentSource(TestOrder::query()))->withQuery($q);

        $this->assertSame(3, $shaped->count());
    }

    public function test_apply_qb_with_resource_wraps_atom_condition_in_single_child_group(): void
    {
        $resource = new MutableOrdersResource;
        $source = new EloquentSource(TestOrder::query(), $resource);

        $q = new Query;
        $q->qbRoot = new AtomCondition('status', Operator::Eq, 'paid');

        $this->assertSame(1, $source->withQuery($q)->count());
    }
}
