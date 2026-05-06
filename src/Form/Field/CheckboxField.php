<?php

namespace Mercurio\Tables\Form\Field;

final class CheckboxField extends FormField
{
    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function viewName(): string
    {
        return 'checkbox';
    }
}
