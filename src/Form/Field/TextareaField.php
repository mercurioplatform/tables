<?php

namespace Mercurio\Tables\Form\Field;

final class TextareaField extends FormField
{
    protected int $rowsCount = 3;

    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function rows(int $rows): self
    {
        $this->rowsCount = max(1, $rows);

        return $this;
    }

    public function getRows(): int
    {
        return $this->rowsCount;
    }

    public function placeholder(string $value): self
    {
        $this->attrs['placeholder'] = $value;

        return $this;
    }

    public function viewName(): string
    {
        return 'textarea';
    }
}
