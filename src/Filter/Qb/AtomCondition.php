<?php

namespace Mercurio\Tables\Filter\Qb;

use Mercurio\Tables\Filter\Operator;

final readonly class AtomCondition
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value,
        public bool $not = false,
    ) {}
}
