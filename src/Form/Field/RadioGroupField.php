<?php

namespace Mercurio\Tables\Form\Field;

use Illuminate\Support\Facades\Log;

final class RadioGroupField extends FormField
{
    /** @var array<int|string, string> */
    protected array $options = [];

    protected ?string $enumClass = null;

    protected bool $inline = false;

    /** @param array<int|string, string> $options */
    public static function options(string $name, string $label, array $options): self
    {
        $field = new self($name, $label);
        $field->options = $options;

        return $field;
    }

    public static function enum(string $name, string $label, string $enumClass): self
    {
        $field = new self($name, $label);
        $field->enumClass = $enumClass;

        return $field;
    }

    public function inline(bool $flag = true): self
    {
        $this->inline = $flag;

        return $this;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    /** @return array<int|string, string> */
    public function getOptions(): array
    {
        if ($this->enumClass !== null) {
            if (! enum_exists($this->enumClass)) {
                Log::warning('tables.form.invalid_enum', [
                    'field' => $this->name,
                    'class' => $this->enumClass,
                ]);

                return [];
            }

            $out = [];
            foreach ($this->enumClass::cases() as $case) {
                $key = property_exists($case, 'value') ? $case->value : $case->name;
                $label = method_exists($case, 'label') ? $case->label() : $case->name;
                $out[$key] = $label;
            }

            return $out;
        }

        return $this->options;
    }

    public function viewName(): string
    {
        return 'radio-group';
    }
}
