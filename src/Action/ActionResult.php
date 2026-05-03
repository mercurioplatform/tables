<?php

namespace Mercurio\Tables\Action;

final class ActionResult
{
    public function __construct(
        public readonly int $affected,
        public readonly int $missing = 0,
        public readonly ?string $message = null,
    ) {}
}
