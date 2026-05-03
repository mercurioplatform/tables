<?php

namespace Mercurio\Tables\Action;

use InvalidArgumentException;

final class BulkAction
{
    public readonly string $name;

    public readonly string $label;

    protected string $kind = 'instant';

    protected ?string $handler = null;

    /** @var array<string, mixed> */
    protected array $payload = [];

    protected ?string $ability = null;

    protected string $variant = 'default';

    protected ?string $confirmText = null;

    protected ?string $icon = null;

    protected function __construct(string $name, string $label)
    {
        $this->name = $name;
        $this->label = $label;
    }

    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function kind(string $kind): self
    {
        if (! in_array($kind, ['instant', 'confirm'], true)) {
            throw new InvalidArgumentException("Unsupported BulkAction kind: {$kind}");
        }

        $this->kind = $kind;

        return $this;
    }

    public function instant(): self
    {
        $this->kind = 'instant';

        return $this;
    }

    public function confirm(?string $text = null): self
    {
        $this->kind = 'confirm';

        if ($text !== null) {
            $this->confirmText = $text;
        }

        return $this;
    }

    public function handler(string $class): self
    {
        $this->handler = $class;

        return $this;
    }

    /** @param array<string, mixed> $payload */
    public function payload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function ability(string $name): self
    {
        $this->ability = $name;

        return $this;
    }

    public function variant(string $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    public function confirmText(string $text): self
    {
        $this->confirmText = $text;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHandler(): ?string
    {
        return $this->handler;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAbility(): ?string
    {
        return $this->ability;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    public function getConfirmText(): ?string
    {
        return $this->confirmText;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
