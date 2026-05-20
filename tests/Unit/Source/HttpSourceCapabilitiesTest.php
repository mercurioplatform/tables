<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Closure;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Tests\TestCase;

final class HttpSourceCapabilitiesTest extends TestCase
{
    private function nullFetch(): Closure
    {
        return fn (Query $q, ?string $cursor): array => ['rows' => [], 'nextCursor' => null];
    }

    public function test_default_capabilities_matrix(): void
    {
        $caps = HttpSource::for($this->nullFetch())->capabilities();

        $expected = new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: false,
            cursor: true,
            mutate: false,
            stream: true,
        );

        $this->assertEquals($expected, $caps);
    }

    public function test_custom_capabilities_via_for_uses_passed_vo(): void
    {
        $caps = HttpSource::for(
            fetch: $this->nullFetch(),
            capabilities: new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: true,
                cursor: false,
                mutate: false,
                stream: true,
            ),
        )->capabilities();

        $this->assertTrue($caps->count);
        $this->assertFalse($caps->cursor);
    }

    public function test_for_factory_minimal_only_fetch_required(): void
    {
        $source = HttpSource::for($this->nullFetch());

        $this->assertNull($source->probe());
        $this->assertNull($source->count(), 'default count=false should short-circuit to null without calling fetcher');
    }

    public function test_for_factory_full_signature_constructs_without_error(): void
    {
        $source = HttpSource::for(
            fetch: $this->nullFetch(),
            capabilities: new Capabilities(count: true),
            resource: null,
            findOne: null,
            findMany: null,
            operatorWhitelist: ['id' => [Operator::Eq]],
            cacheTtlSeconds: 60,
            cachePrefix: 'custom',
            primaryKey: 'uuid',
        );

        $this->assertSame(true, $source->capabilities()->count);
    }

    public function test_probe_returns_null(): void
    {
        $this->assertNull(HttpSource::for($this->nullFetch())->probe());
    }

    public function test_direct_ctor_with_all_named_params(): void
    {
        $source = new HttpSource(
            fetch: $this->nullFetch(),
            query: new Query,
            capabilities: null,
            resource: null,
            findOne: null,
            findMany: null,
            operatorWhitelist: null,
            cacheTtlSeconds: null,
            cachePrefix: null,
            primaryKey: 'id',
            cursor: null,
        );

        $this->assertTrue($source->capabilities()->cursor, 'default cursor capability is true for HttpSource');
    }

    public function test_capabilities_returned_instance_is_not_shared_between_sources(): void
    {
        $a = HttpSource::for($this->nullFetch())->capabilities();
        $b = HttpSource::for($this->nullFetch())->capabilities();

        $this->assertEquals($a, $b);
        $this->assertNotSame($a, $b, 'capabilities() must return a fresh VO instance per call to avoid accidental sharing');
    }
}
