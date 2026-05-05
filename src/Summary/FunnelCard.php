<?php

namespace Mercurio\Tables\Summary;

final class FunnelCard
{
    public function __construct(
        public readonly string $label,
        public readonly string|int $value,
        public readonly ?string $viewKey = null,
        public readonly string $kind = 'neutral',
        public readonly ?string $delta = null,
    ) {}
}
