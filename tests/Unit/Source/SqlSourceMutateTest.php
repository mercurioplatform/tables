<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Source\SqlSource;
use Mercurio\Tables\Tests\TestCase;

final class SqlSourceMutateTest extends TestCase
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

    public function test_update_throws_logic_exception_by_default(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SqlSource is read-only by default');

        SqlSource::for('test_orders')->update(1, ['status' => 'paid']);
    }

    public function test_update_logs_warning_before_throwing(): void
    {
        Log::spy();

        try {
            SqlSource::for('test_orders')->update(1, ['status' => 'paid']);
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->with('tables.source.sql.mutate_denied', [
                'resource' => null,
                'id' => 1,
            ])
            ->once();
    }

    public function test_update_warning_includes_resource_key_when_resource_present(): void
    {
        Log::spy();

        $source = SqlSource::for('test_orders', null, 'id', null, $this->resourceWithKey('sql_orders'));

        try {
            $source->update(1, ['status' => 'paid']);
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->with('tables.source.sql.mutate_denied', [
                'resource' => 'sql_orders',
                'id' => 1,
            ])
            ->once();
    }

    public function test_update_with_mutate_capability_true_applies_changes(): void
    {
        $source = SqlSource::for(
            'test_orders',
            null,
            'id',
            new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: true,
                cursor: false,
                mutate: true,
                stream: true,
                qbTree: true,
            ),
        );

        $result = $source->update(1, ['status' => 'paid']);

        $this->assertNotNull($result);
        $this->assertSame('paid', (string) data_get($result, 'status'));
        $this->assertSame('paid', DB::table('test_orders')->where('id', 1)->value('status'));
    }

    public function test_update_with_mutate_true_returns_null_for_missing_id(): void
    {
        $source = SqlSource::for(
            'test_orders',
            null,
            'id',
            new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: true,
                cursor: false,
                mutate: true,
                stream: true,
                qbTree: true,
            ),
        );

        $this->assertNull($source->update(999, ['status' => 'paid']));
    }
}
