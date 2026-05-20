<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Closure;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Tests\TestCase;
use Mercurio\Tables\View\SavedView;
use Mockery;

final class HttpSourceQueryTest extends TestCase
{
    /** @var array<int, array{query: Query, cursor: ?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    private function recordingFetch(array $pages): Closure
    {
        $i = 0;

        return function (Query $q, ?string $cursor) use (&$i, $pages): array {
            $this->calls[] = ['query' => clone $q, 'cursor' => $cursor];
            $page = $pages[$i] ?? end($pages);
            $i++;

            return $page;
        };
    }

    /**
     * @param  ?array<string, list<Operator>>  $whitelist
     */
    private function source(?Capabilities $caps = null, ?ListResource $resource = null, ?array $whitelist = null): HttpSource
    {
        return HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [], 'nextCursor' => null]]),
            capabilities: $caps,
            resource: $resource,
            operatorWhitelist: $whitelist,
        );
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
        $source = $this->source();
        $shaped = $source->withQuery(new Query);

        $this->assertNotSame($source, $shaped);
        $this->assertNull($source->count(), 'original source keeps default capabilities (count=false → null)');
    }

    public function test_with_query_passes_query_object_to_fetcher_on_page(): void
    {
        $q = new Query;
        $q->search = 'foo';
        $q->searchableColumns = ['name'];

        $this->source()->withQuery($q)->page(1, 10);

        $this->assertSame('foo', $this->calls[0]['query']->search);
        $this->assertSame(['name'], $this->calls[0]['query']->searchableColumns);
    }

    public function test_apply_operator_whitelist_keeps_conditions_for_fields_without_entry(): void
    {
        $whitelist = ['status' => [Operator::Eq]];

        $q = new Query;
        $q->conditions = [new FilterCondition('customer', Operator::Contains, 'A')];

        $this->source(whitelist: $whitelist)->withQuery($q)->page(1, 10);

        $this->assertCount(1, $this->calls[0]['query']->conditions);
    }

    public function test_apply_operator_whitelist_keeps_allowed_operators(): void
    {
        $whitelist = ['status' => [Operator::Eq, Operator::In]];

        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Eq, 'paid')];

        $this->source(whitelist: $whitelist)->withQuery($q)->page(1, 10);

        $this->assertCount(1, $this->calls[0]['query']->conditions);
    }

    public function test_apply_operator_whitelist_skips_disallowed_operator_and_warns(): void
    {
        Log::spy();

        $whitelist = ['status' => [Operator::Eq]];

        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Contains, 'pa')];

        $this->source(whitelist: $whitelist)->withQuery($q)->page(1, 10);

        $this->assertSame([], $this->calls[0]['query']->conditions);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.http.operator_not_allowed',
                Mockery::on(static fn (array $ctx): bool => ($ctx['field'] ?? null) === 'status'
                    && ($ctx['operator'] ?? null) === 'contains'),
            )
            ->once();
    }

    public function test_apply_operator_whitelist_noop_when_null(): void
    {
        Log::spy();

        $q = new Query;
        $q->conditions = [new FilterCondition('status', Operator::Contains, 'X')];

        $this->source()->withQuery($q)->page(1, 10);

        $this->assertCount(1, $this->calls[0]['query']->conditions);

        Log::shouldNotHaveReceived('warning', ['tables.source.http.operator_not_allowed', Mockery::any()]);
    }

    public function test_apply_operator_whitelist_noop_when_query_conditions_empty(): void
    {
        Log::spy();

        $whitelist = ['status' => [Operator::Eq]];

        $this->source(whitelist: $whitelist)->withQuery(new Query)->page(1, 10);

        $this->assertSame([], $this->calls[0]['query']->conditions);

        Log::shouldNotHaveReceived('warning', ['tables.source.http.operator_not_allowed', Mockery::any()]);
    }

    public function test_guard_saved_view_scope_warns_for_eloquent_scope_view(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'archived';

        $this->source(resource: $this->resourceWithSavedViewScope())->withQuery($q)->page(1, 10);

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.http.saved_view_scope_unsupported',
                Mockery::on(static fn (array $ctx): bool => ($ctx['resource'] ?? null) === 'r'
                    && ($ctx['view'] ?? null) === 'archived'),
            )
            ->once();
    }

    public function test_guard_saved_view_scope_does_not_warn_for_conditions_form(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'paid';

        $this->source(resource: $this->resourceWithSavedViewConditions())->withQuery($q)->page(1, 10);

        Log::shouldNotHaveReceived('warning', ['tables.source.http.saved_view_scope_unsupported', Mockery::any()]);
    }

    public function test_guard_saved_view_scope_noop_when_resource_null(): void
    {
        Log::spy();

        $q = new Query;
        $q->savedViewKey = 'archived';

        $this->source()->withQuery($q)->page(1, 10);

        Log::shouldNotHaveReceived('warning', ['tables.source.http.saved_view_scope_unsupported', Mockery::any()]);
    }

    public function test_guard_qb_root_warns_and_nullifies_qb_root(): void
    {
        Log::spy();

        $q = new Query;
        $q->qbRoot = new AtomGroup('AND', false, [new AtomCondition('status', Operator::Eq, 'paid')]);

        $this->source()->withQuery($q)->page(1, 10);

        $this->assertNull($this->calls[0]['query']->qbRoot);

        Log::shouldHaveReceived('warning')
            ->with('tables.source.http.qb_unsupported', Mockery::any())
            ->once();
    }

    public function test_guard_qb_root_noop_when_qb_root_null(): void
    {
        Log::spy();

        $this->source()->withQuery(new Query)->page(1, 10);

        Log::shouldNotHaveReceived('warning', ['tables.source.http.qb_unsupported', Mockery::any()]);
    }
}
