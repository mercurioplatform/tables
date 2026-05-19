<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class ArraySourceMutateTest extends TestCase
{
    public function test_update_throws_logic_exception(): void
    {
        $source = new ArraySource([['id' => 1, 'status' => 'pending']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ArraySource is read-only');

        $source->update(1, ['status' => 'paid']);
    }

    public function test_update_logs_warning_before_throwing(): void
    {
        Log::spy();

        $source = new ArraySource([['id' => 42]]);

        try {
            $source->update(42, ['k' => 'v', 'x' => 'y']);
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->with('tables.array_source.update_called_on_readonly', [
                'resource' => null,
                'id' => 42,
                'columns' => ['k', 'x'],
            ])
            ->once();
    }

    public function test_update_warning_includes_resource_key_when_resource_present(): void
    {
        $resource = new TestOrdersResource;
        $source = new ArraySource([['id' => 1]], null, 'id', $resource);

        Log::spy();

        try {
            $source->update(1, ['s' => 'x']);
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->with('tables.array_source.update_called_on_readonly', [
                'resource' => 'test_orders',
                'id' => 1,
                'columns' => ['s'],
            ])
            ->once();
    }
}
