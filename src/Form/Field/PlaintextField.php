<?php

namespace Mercurio\Tables\Form\Field;

final class PlaintextField extends FormField
{
    public static function make(string $name, string $label = ''): self
    {
        return new self($name, $label);
    }

    public function viewName(): string
    {
        return 'plaintext';
    }

    public function compileRules(): array
    {
        return [];
    }

    public function hasRules(): bool
    {
        return false;
    }
}
