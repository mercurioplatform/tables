<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Tests\TestCase;

final class HttpSourceCacheTest extends TestCase
{
    /** @var array<int, array{query: Query, cursor: ?string}> */
    private array $calls = [];

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        Cache::flush();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array{query: Query, cursor: ?string}>  $sink
     */
    private function recordingFetch(array $pages, array &$sink): Closure
    {
        $i = 0;

        return function (Query $q, ?string $cursor) use (&$i, $pages, &$sink): array {
            $sink[] = ['query' => clone $q, 'cursor' => $cursor];
            $page = $pages[$i] ?? end($pages);
            $i++;

            return $page;
        };
    }

    private function resourceWithKey(string $key): ListResource
    {
        return new class($key) extends ListResource
        {
            public function __construct(private readonly string $resourceKey) {}

            public function key(): string
            {
                return $this->resourceKey;
            }

            public function fields(): array
            {
                return [];
            }

            public function source(): ?Source
            {
                return null;
            }
        };
    }

    public function test_fetch_bypasses_cache_when_ttl_null(): void
    {
        $source = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 1]], 'nextCursor' => null]], $this->calls),
            cacheTtlSeconds: null,
        );

        $source->withQuery(new Query)->page(1, 10);
        $source->withQuery(new Query)->page(1, 10);

        $this->assertCount(2, $this->calls, 'ttl=null disables cache, fetcher is invoked every time');
    }

    public function test_fetch_bypasses_cache_when_ttl_zero(): void
    {
        $source = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 1]], 'nextCursor' => null]], $this->calls),
            cacheTtlSeconds: 0,
        );

        $source->withQuery(new Query)->page(1, 10);
        $source->withQuery(new Query)->page(1, 10);

        $this->assertCount(2, $this->calls, 'ttl=0 is treated as cache-disabled, not cache-forever');
    }

    public function test_fetch_writes_to_cache_on_miss_and_reads_on_hit(): void
    {
        $source = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 1]], 'nextCursor' => null]], $this->calls),
            cacheTtlSeconds: 60,
        );

        $shaped = $source->withQuery(new Query);

        $first = $shaped->page(1, 10);
        $this->assertCount(1, $this->calls, 'cache miss on first call triggers fetcher');

        $second = $shaped->page(1, 10);
        $this->assertCount(1, $this->calls, 'cache hit on second call must not invoke fetcher');

        $this->assertSame(1, $first->rows[0]['id']);
        $this->assertSame(1, $second->rows[0]['id']);
    }

    public function test_cache_key_changes_with_query_search_field(): void
    {
        $callsA = [];
        $callsB = [];

        $sourceA = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 1]], 'nextCursor' => null]], $callsA),
            cacheTtlSeconds: 60,
        );
        $sourceB = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 2]], 'nextCursor' => null]], $callsB),
            cacheTtlSeconds: 60,
        );

        $qA = new Query;
        $qA->search = 'foo';
        $qB = new Query;
        $qB->search = 'bar';

        $sourceA->withQuery($qA)->page(1, 10);
        $sourceB->withQuery($qB)->page(1, 10);

        $this->assertCount(1, $callsA, 'sourceA with Query{search=foo} must invoke its own fetcher');
        $this->assertCount(1, $callsB, 'sourceB with Query{search=bar} must invoke its own fetcher');
    }

    public function test_cache_key_changes_with_cursor(): void
    {
        $source = HttpSource::for(
            fetch: $this->recordingFetch([
                ['rows' => [['id' => 1]], 'nextCursor' => 'cur-2'],
                ['rows' => [['id' => 2]], 'nextCursor' => null],
            ], $this->calls),
            cacheTtlSeconds: 60,
        );

        iterator_to_array($source->withQuery(new Query)->stream(10), false);
        $this->assertCount(2, $this->calls, 'two different cursors → two cache misses → two fetcher calls');

        iterator_to_array($source->withQuery(new Query)->stream(10), false);
        $this->assertCount(2, $this->calls, 'second stream must hit cache for both cursors — no new fetcher calls');
    }

    public function test_cache_key_changes_with_resource_key(): void
    {
        $callsA = [];
        $callsB = [];

        $sourceA = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 1]], 'nextCursor' => null]], $callsA),
            resource: $this->resourceWithKey('a'),
            cacheTtlSeconds: 60,
        );
        $sourceB = HttpSource::for(
            fetch: $this->recordingFetch([['rows' => [['id' => 2]], 'nextCursor' => null]], $callsB),
            resource: $this->resourceWithKey('b'),
            cacheTtlSeconds: 60,
        );

        $sourceA->withQuery(new Query)->page(1, 10);
        $sourceB->withQuery(new Query)->page(1, 10);

        $this->assertCount(1, $callsA);
        $this->assertCount(1, $callsB, 'different resource keys produce different cache keys → both fetchers invoked');
    }
}
