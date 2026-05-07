<?php

namespace Mercurio\Tables\Page;

final class EmptyState
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $iconName = null,
        public readonly ?HeaderAction $cta = null,
    ) {}

    public static function make(string $title): self
    {
        return new self($title);
    }

    public function description(string $description): self
    {
        return new self($this->title, $description, $this->iconName, $this->cta);
    }

    public function icon(string $iconName): self
    {
        return new self($this->title, $this->description, $iconName, $this->cta);
    }

    public function cta(HeaderAction $cta): self
    {
        return new self($this->title, $this->description, $this->iconName, $cta);
    }
}
