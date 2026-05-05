<?php

namespace Mercurio\Tables\Summary;

final class KpiCard
{
    /**
     * @param  array<int, int|float>|null  $sparklineValues
     */
    public function __construct(
        public readonly string $title,
        public readonly string $value,
        public readonly ?string $suffix = null,
        public readonly ?string $delta = null,
        public readonly string $deltaTone = 'neutral',
        public readonly ?array $sparklineValues = null,
        public readonly bool $sparklineFilled = true,
    ) {}
}
