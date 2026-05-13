<?php

namespace Mercurio\Tables\Summary;

final class Summary
{
    /**
     * @param  array<int, SummaryCard>  $cards
     */
    public function __construct(public readonly array $cards) {}
}
