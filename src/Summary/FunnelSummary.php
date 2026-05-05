<?php

namespace Mercurio\Tables\Summary;

final class FunnelSummary extends Summary
{
    /**
     * @param  array<int, FunnelCard>  $cards
     */
    public function __construct(public readonly array $cards) {}
}
