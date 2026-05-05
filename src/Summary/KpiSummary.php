<?php

namespace Mercurio\Tables\Summary;

final class KpiSummary extends Summary
{
    /**
     * @param  array<int, KpiCard>  $cards
     */
    public function __construct(public readonly array $cards) {}
}
