<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\DB;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\SqlSource;
use Mercurio\Tables\Source\Support\SqlSourceModel;
use Mercurio\Tables\Tests\TestCase;

final class SqlSourceCapabilitiesTest extends TestCase
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

    public function test_default_capabilities_matrix(): void
    {
        $caps = SqlSource::for('test_orders')->capabilities();

        $this->assertTrue($caps->filter);
        $this->assertTrue($caps->sort);
        $this->assertTrue($caps->search);
        $this->assertTrue($caps->count);
        $this->assertFalse($caps->cursor);
        $this->assertFalse($caps->mutate);
        $this->assertTrue($caps->stream);
        $this->assertTrue($caps->qbTree, 'SqlSource overrides qbTree default to true');
    }

    public function test_custom_capabilities_via_ctor_uses_passed_vo(): void
    {
        $caps = SqlSource::for(
            'test_orders',
            null,
            'id',
            new Capabilities(mutate: true, qbTree: false),
        )->capabilities();

        $this->assertTrue($caps->mutate, 'host-passed mutate=true overrides default');
        $this->assertFalse($caps->qbTree, 'host-passed qbTree=false overrides default');
    }

    public function test_for_factory_with_explicit_connection(): void
    {
        $this->assertSame(3, SqlSource::for('test_orders', 'testing')->count());
    }

    public function test_for_factory_with_null_connection_uses_default(): void
    {
        $this->assertSame(3, SqlSource::for('test_orders', null)->count());
    }

    public function test_for_factory_custom_primary_key(): void
    {
        $result = SqlSource::for('test_orders', null, 'number')->find('A-1001');

        $this->assertNotNull($result);
        $this->assertSame(1, (int) data_get($result, 'id'));
    }

    public function test_probe_returns_null(): void
    {
        $this->assertNull(SqlSource::for('test_orders')->probe());
    }

    public function test_direct_ctor_with_external_builder(): void
    {
        $model = new SqlSourceModel;
        $model->setTable('test_orders');
        $model->setKeyName('id');

        $source = new SqlSource($model->newQuery());

        $this->assertSame(3, $source->count());
    }

    public function test_capabilities_returned_instance_does_not_share_state_between_sources(): void
    {
        $a = SqlSource::for('test_orders');
        $b = SqlSource::for('test_orders');

        $capsA = $a->capabilities();
        $capsB = $b->capabilities();

        $this->assertNotSame($capsA, $capsB, 'each call constructs a fresh Capabilities VO');
        $this->assertEquals($capsA, $capsB, 'value equality holds');
    }
}
