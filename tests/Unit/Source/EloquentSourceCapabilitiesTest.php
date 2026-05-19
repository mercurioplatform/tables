<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrder;
use Mercurio\Tables\Tests\TestCase;

final class EloquentSourceCapabilitiesTest extends TestCase
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

    public function test_capabilities_matrix(): void
    {
        $caps = (new EloquentSource(TestOrder::query()))->capabilities();

        $expected = new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: true,
            cursor: false,
            mutate: true,
            stream: true,
            qbTree: true,
        );

        $this->assertEquals($expected, $caps);
    }

    public function test_probe_returns_new_instance_of_underlying_model(): void
    {
        $probe = (new EloquentSource(TestOrder::query()))->probe();

        $this->assertInstanceOf(TestOrder::class, $probe);
        $this->assertFalse($probe->exists);
        $this->assertNull($probe->id);
    }

    public function test_get_builder_returns_clone_not_reference(): void
    {
        $source = new EloquentSource(TestOrder::query());

        $a = $source->getBuilder();
        $b = $source->getBuilder();

        $this->assertNotSame($a, $b);

        $sqlBefore = $a->toSql();
        $a->where('status', 'nonexistent');

        $c = $source->getBuilder();

        $this->assertSame($sqlBefore, $c->toSql());
        $this->assertSame(3, $c->count());
    }
}
