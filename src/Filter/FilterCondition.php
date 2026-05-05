<?php

namespace Mercurio\Tables\Filter;

final class FilterCondition
{
    public function __construct(
        public readonly string $field,
        public readonly Operator $operator,
        public readonly mixed $value,
    ) {}
}
