<?php

namespace Mercurio\Tables\Filter\Qb;

use Mercurio\Tables\Filter\Operator;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see \Mercurio\Tables\Filter\Operator}, {@see \Mercurio\Tables\ListResource::query()}.
 */
final readonly class AtomCondition
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value,
        public bool $not = false,
    ) {}
}
