<?php

namespace Mercurio\Tables\Page;

final class HeaderAction
{
    /**
     * @param  array<string, string>  $attrs
     */
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $icon = null,
        public readonly string $variant = 'dark',
        public readonly string $size = 'sm',
        public readonly ?string $target = null,
        public readonly array $attrs = [],
    ) {}

    public static function make(string $label, string $url): self
    {
        return new self($label, $url);
    }

    public function icon(string $icon): self
    {
        return new self($this->label, $this->url, $icon, $this->variant, $this->size, $this->target, $this->attrs);
    }

    public function variant(string $variant): self
    {
        return new self($this->label, $this->url, $this->icon, $variant, $this->size, $this->target, $this->attrs);
    }

    public function size(string $size): self
    {
        return new self($this->label, $this->url, $this->icon, $this->variant, $size, $this->target, $this->attrs);
    }

    public function target(?string $target): self
    {
        return new self($this->label, $this->url, $this->icon, $this->variant, $this->size, $target, $this->attrs);
    }

    /**
     * @param  array<string, string>  $attrs
     */
    public function attrs(array $attrs): self
    {
        return new self(
            $this->label,
            $this->url,
            $this->icon,
            $this->variant,
            $this->size,
            $this->target,
            array_merge($this->attrs, $attrs),
        );
    }
}
