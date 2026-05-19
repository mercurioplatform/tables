<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\SavedViewSerializer;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Tests\TestCase;

final class SavedViewSerializerTest extends TestCase
{
    public function test_empty_array_yields_empty_array(): void
    {
        $this->assertSame([], SavedViewSerializer::serializeConditions([]));
    }

    public function test_single_scalar_condition(): void
    {
        $out = SavedViewSerializer::serializeConditions([
            new FilterCondition('status', Operator::Eq, 'paid'),
        ]);

        $this->assertSame(
            [['field' => 'status', 'operator' => 'eq', 'value' => 'paid']],
            $out,
        );
    }

    public function test_multiple_conditions_preserve_order(): void
    {
        $out = SavedViewSerializer::serializeConditions([
            new FilterCondition('status', Operator::Eq, 'paid'),
            new FilterCondition('total', Operator::Gte, 1000),
        ]);

        $this->assertCount(2, $out);
        $this->assertSame('status', $out[0]['field']);
        $this->assertSame('total', $out[1]['field']);
        $this->assertSame('gte', $out[1]['operator']);
    }

    public function test_operator_value_mapping(): void
    {
        $out = SavedViewSerializer::serializeConditions([
            new FilterCondition('x', Operator::In, ['a', 'b']),
            new FilterCondition('y', Operator::Between, [1, 10]),
            new FilterCondition('z', Operator::Empty_, null),
        ]);

        $this->assertSame('in', $out[0]['operator']);
        $this->assertSame('between', $out[1]['operator']);
        $this->assertSame('empty', $out[2]['operator']);
    }

    public function test_array_value_is_passed_through(): void
    {
        $out = SavedViewSerializer::serializeConditions([
            new FilterCondition('total', Operator::Between, [100, 500]),
        ]);

        $this->assertSame([100, 500], $out[0]['value']);
    }
}
