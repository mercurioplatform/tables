<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Collection;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Tests\TestCase;

final class ArraySourceCapabilitiesTest extends TestCase
{
    public function test_default_capabilities_matrix(): void
    {
        $caps = (new ArraySource([]))->capabilities();

        $expected = new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: true,
            cursor: false,
            mutate: false,
            stream: true,
            qbTree: true,
        );

        $this->assertEquals($expected, $caps);
    }

    public function test_custom_capabilities_via_ctor_uses_passed_vo_defaults_not_array_source_defaults(): void
    {
        // Передаём raw Capabilities VO → используются её ctor-defaults (qbTree=false),
        // а не ArraySource auto-construct defaults (qbTree=true) из lines 71-80.
        $caps = (new ArraySource([], new Capabilities(filter: true, sort: false, search: true, count: true)))->capabilities();

        $this->assertFalse($caps->sort);
        $this->assertFalse($caps->mutate);
        $this->assertFalse($caps->qbTree);
    }

    public function test_probe_returns_null(): void
    {
        $this->assertNull((new ArraySource([]))->probe());
    }

    public function test_get_rows_returns_underlying_collection(): void
    {
        $rows = (new ArraySource([['id' => 1], ['id' => 2]]))->getRows();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertSame(2, $rows->count());
        $this->assertSame([['id' => 1], ['id' => 2]], $rows->all());
    }

    public function test_get_rows_reindexes_string_keys_to_int(): void
    {
        $rows = (new ArraySource(['a' => ['id' => 1], 'b' => ['id' => 2]]))->getRows();

        $this->assertSame([0, 1], $rows->keys()->all());
    }

    public function test_ctor_accepts_iterable_generator(): void
    {
        $gen = (function () {
            yield ['id' => 1];
            yield ['id' => 2];
        })();

        $source = new ArraySource($gen);

        $this->assertSame(2, $source->getRows()->count());
        $this->assertSame([1, 2], array_map(static fn (array $r): int => $r['id'], $source->getRows()->all()));
    }

    public function test_default_primary_key_is_id(): void
    {
        $this->assertSame('id', (new ArraySource([['id' => 42]]))->primaryKey);
    }

    public function test_custom_primary_key(): void
    {
        $this->assertSame('code', (new ArraySource([['code' => 'X']], primaryKey: 'code'))->primaryKey);
    }
}
