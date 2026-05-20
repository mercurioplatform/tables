<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Closure;
use Generator;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class HttpSourceFindTest extends TestCase
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

    public function test_find_uses_find_one_closure_when_provided(): void
    {
        $findOne = static fn (int|string $id): array => ['id' => $id, 'name' => "Item #{$id}"];

        $result = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => []]]),
            findOne: $findOne,
        )->find(42);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['id']);
        $this->assertSame([], $this->calls, 'findOne-closure must short-circuit, fetcher should not be invoked');
    }

    public function test_find_with_find_one_closure_returning_null_returns_null(): void
    {
        $findOne = static fn (): mixed => null;

        $result = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => []]]),
            findOne: $findOne,
        )->find(99);

        $this->assertNull($result);
        $this->assertSame([], $this->calls);
    }

    public function test_find_with_find_one_closure_returning_empty_array_returns_null(): void
    {
        $findOne = static fn (): array => [];

        $result = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => []]]),
            findOne: $findOne,
        )->find(99);

        $this->assertNull($result, 'empty-array result is treated as a miss');
        $this->assertSame([], $this->calls);
    }

    public function test_find_fallback_uses_filter_condition_on_primary_key(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => [['id' => 7, 'name' => 'Seven']], 'nextCursor' => null],
        ]));

        $result = $source->find(7);

        $this->assertIsArray($result);
        $this->assertSame(7, $result['id']);

        $this->assertCount(1, $this->calls);
        $conditions = $this->calls[0]['query']->conditions;
        $this->assertCount(1, $conditions);
        $this->assertInstanceOf(FilterCondition::class, $conditions[0]);
        $this->assertSame('id', $conditions[0]->field);
        $this->assertSame(Operator::Eq, $conditions[0]->operator);
        $this->assertSame(7, $conditions[0]->value);
    }

    public function test_find_fallback_returns_null_for_empty_fetch_payload(): void
    {
        $source = HttpSource::for($this->recordingFetch([['rows' => []]]));

        $this->assertNull($source->find(99));
    }

    public function test_find_fallback_blocked_by_whitelist_warns_and_returns_null(): void
    {
        Log::spy();

        $whitelist = ['id' => [Operator::Contains]];

        $source = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => []]]),
            operatorWhitelist: $whitelist,
        );

        $this->assertNull($source->find(7));
        $this->assertSame([], $this->calls, 'fetcher must not be called when Eq is missing from whitelist');

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.http.find_fallback_blocked_by_whitelist',
                Mockery::on(static fn (array $ctx): bool => ($ctx['primary_key'] ?? null) === 'id'
                    && ($ctx['allowed'] ?? null) === ['contains']),
            )
            ->once();
    }

    public function test_find_many_empty_array_returns_empty(): void
    {
        $source = HttpSource::for($this->recordingFetch([['rows' => []]]));

        $this->assertSame([], $source->findMany([]));
        $this->assertSame([], $this->calls);
    }

    public function test_find_many_uses_find_many_closure_when_provided(): void
    {
        $findMany = static fn (array $ids): array => array_map(static fn (int|string $id): array => ['id' => $id], $ids);

        $result = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => []]]),
            findMany: $findMany,
        )->findMany([1, 2, 3]);

        $this->assertCount(3, $result);
        $this->assertSame([], $this->calls);
    }

    public function test_find_many_find_many_closure_returning_generator_is_materialized(): void
    {
        $findMany = static function (array $ids): Generator {
            foreach ($ids as $id) {
                yield ['id' => $id];
            }
        };

        $result = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => []]]),
            findMany: $findMany,
        )->findMany([1, 2]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function test_find_many_fallback_uses_in_operator_on_primary_key(): void
    {
        $source = HttpSource::for($this->recordingFetch([
            ['rows' => [['id' => 1], ['id' => 3]], 'nextCursor' => null],
        ]));

        $result = $source->findMany([1, 3]);

        $this->assertCount(2, (array) $result);

        $this->assertCount(1, $this->calls);
        $conditions = $this->calls[0]['query']->conditions;
        $this->assertCount(1, $conditions);
        $this->assertInstanceOf(FilterCondition::class, $conditions[0]);
        $this->assertSame('id', $conditions[0]->field);
        $this->assertSame(Operator::In, $conditions[0]->operator);
        $this->assertSame([1, 3], $conditions[0]->value);
    }

    public function test_find_many_linear_fallback_warns_when_in_blocked_by_whitelist(): void
    {
        Log::spy();

        $whitelist = ['id' => [Operator::Eq]];

        $source = HttpSource::for(
            fetch: $this->recordingFetch([
                ['rows' => [['id' => 1]], 'nextCursor' => null],
                ['rows' => [], 'nextCursor' => null],
            ]),
            operatorWhitelist: $whitelist,
        );

        $result = $source->findMany([1, 2]);

        $this->assertCount(1, (array) $result, 'id=2 is skipped because its fetch returned no rows');
        $this->assertCount(2, $this->calls, 'linear fallback issues one find() per id');

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.http.find_many.linear_fallback',
                Mockery::on(static fn (array $ctx): bool => ($ctx['count'] ?? null) === 2),
            )
            ->once();
    }
}
