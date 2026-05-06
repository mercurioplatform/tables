<?php

namespace Mercurio\Tables\Form\Field;

use Closure;
use Illuminate\Support\Facades\Log;

final class SelectField extends FormField
{
    /** @var array<int|string, string> */
    protected array $options = [];

    protected ?string $enumClass = null;

    /** @var Closure|null fn(): array<int|string, string> */
    protected ?Closure $relationResolver = null;

    protected ?string $emptyOption = null;

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

    public static function relation(string $name, string $label, Closure $resolver): self
    {
        $field = new self($name, $label);
        $field->relationResolver = $resolver;

        return $field;
    }

    public function empty(?string $label = '— не выбрано —'): self
    {
        $this->emptyOption = $label;

        return $this;
    }

    public function getEmptyLabel(): ?string
    {
        return $this->emptyOption;
    }

    /** @return array<int|string, string> */
    public function getOptions(): array
    {
        if ($this->enumClass !== null) {
            return $this->resolveEnumOptions($this->enumClass);
        }

        if ($this->relationResolver !== null) {
            $resolved = ($this->relationResolver)();

            return is_array($resolved) ? $resolved : [];
        }

        return $this->options;
    }

    /** @return array<int|string, string> */
    private function resolveEnumOptions(string $enumClass): array
    {
        if (! enum_exists($enumClass)) {
            Log::warning('tables.form.invalid_enum', [
                'field' => $this->name,
                'class' => $enumClass,
            ]);

            return [];
        }

        $cases = $enumClass::cases();
        $out = [];
        foreach ($cases as $case) {
            $key = property_exists($case, 'value') ? $case->value : $case->name;
            $label = method_exists($case, 'label') ? $case->label() : $case->name;
            $out[$key] = $label;
        }

        return $out;
    }

    public function viewName(): string
    {
        return 'select';
    }
}
