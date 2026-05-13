<?php

namespace Mercurio\Tables\Filter\Qb;

use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Operator}, {@see ListResource::query()}.
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
