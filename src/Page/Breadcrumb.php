<?php

namespace Mercurio\Tables\Page;

final class Breadcrumb
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $url = null,
    ) {}

    public static function link(string $label, string $url): self
    {
        return new self($label, $url);
    }

    public static function current(string $label): self
    {
        return new self($label, null);
    }
}
