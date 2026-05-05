<?php

namespace Mercurio\Tables\Filter;

use Mercurio\Tables\Filter\Qb\AtomCondition;

final class FilterCondition
{
    public function __construct(
        public readonly string $field,
        public readonly Operator $operator,
        public readonly mixed $value,
    ) {}

    public static function fromAtom(AtomCondition $atom): self
    {
        return new self($atom->field, $atom->operator, $atom->value);
    }
}
