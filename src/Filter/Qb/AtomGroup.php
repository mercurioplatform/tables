<?php

namespace Mercurio\Tables\Filter\Qb;

use InvalidArgumentException;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Operator}, {@see ListResource::query()}.
 */
final class AtomGroup
{
    /** @var array<int, AtomCondition|AtomGroup> */
    public array $children;

    public function __construct(
        public string $op,
        public bool $not,
        array $children,
    ) {
        if ($op !== 'AND' && $op !== 'OR') {
            throw new InvalidArgumentException("AtomGroup::op must be 'AND' or 'OR', got '{$op}'");
        }
        $this->children = array_values($children);
    }

    /**
     * @param  array<int, AtomCondition|AtomGroup>  $children
     */
    public function withChildren(array $children): self
    {
        return new self($this->op, $this->not, $children);
    }
}
