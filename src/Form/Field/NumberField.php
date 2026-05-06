<?php

namespace Mercurio\Tables\Form\Field;

final class NumberField extends FormField
{
    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function step(string $step): self
    {
        $this->attrs['step'] = $step;

        return $this;
    }

    public function min(float $min): self
    {
        $this->attrs['min'] = (string) $min;

        return $this;
    }

    public function max(float $max): self
    {
        $this->attrs['max'] = (string) $max;

        return $this;
    }

    public function placeholder(string $value): self
    {
        $this->attrs['placeholder'] = $value;

        return $this;
    }

    public function viewName(): string
    {
        return 'number';
    }
}
