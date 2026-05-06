<?php

namespace Mercurio\Tables\Form\Field;

final class TextField extends FormField
{
    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function placeholder(string $value): self
    {
        $this->attrs['placeholder'] = $value;

        return $this;
    }

    public function maxlength(int $value): self
    {
        $this->attrs['maxlength'] = (string) $value;

        return $this;
    }

    public function viewName(): string
    {
        return 'text';
    }
}
