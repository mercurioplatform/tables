<?php

namespace Mercurio\Tables\Form\Field;

use Closure;

abstract class FormField
{
    public readonly string $name;

    public readonly string $label;

    protected mixed $defaultValue = null;

    /** @var Closure|null fn($model): mixed */
    protected ?Closure $valueResolver = null;

    protected ?string $helper = null;

    protected bool $required = false;

    /** @var array<string, string> */
    protected array $attrs = [];

    /** @var array<int, mixed> */
    protected array $rules = [];

    /** @var array<string, string> */
    protected array $messages = [];

    protected ?string $attribute = null;

    protected function __construct(string $name, string $label)
    {
        $this->name = $name;
        $this->label = $label;
    }

    abstract public function viewName(): string;

    public function value(mixed $default): static
    {
        $this->defaultValue = $default;

        return $this;
    }

    public function valueFrom(Closure $resolver): static
    {
        $this->valueResolver = $resolver;

        return $this;
    }

    public function helper(?string $text): static
    {
        $this->helper = $text;

        return $this;
    }

    public function required(bool $flag = true): static
    {
        $this->required = $flag;

        return $this;
    }

    /** @param array<string, string> $attrs */
    public function attrs(array $attrs): static
    {
        $this->attrs = array_merge($this->attrs, $attrs);

        return $this;
    }

    public function getDefault(): mixed
    {
        return $this->defaultValue;
    }

    public function getHelper(): ?string
    {
        return $this->helper;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** @return array<string, string> */
    public function getAttrs(): array
    {
        return $this->attrs;
    }

    public function resolveValue(mixed $model = null): mixed
    {
        if ($this->valueResolver !== null && $model !== null) {
            return ($this->valueResolver)($model);
        }

        return $this->defaultValue;
    }

    /** @param array<int, mixed> $rules */
    public function rules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /** @param array<string, string> $messages */
    public function messages(array $messages): static
    {
        $this->messages = $messages;

        return $this;
    }

    public function attribute(string $name): static
    {
        $this->attribute = $name;

        return $this;
    }

    /** @return array<int, mixed> */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @return array<string, string> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getAttribute(): ?string
    {
        return $this->attribute;
    }

    /**
     * Финальный список правил для валидатора. Auto-prepend 'required'
     * если field объявлен ->required() и 'required' ещё не в списке.
     *
     * @return array<int, mixed>
     */
    public function compileRules(): array
    {
        $rules = $this->rules;

        if ($this->required) {
            $hasRequired = false;
            foreach ($rules as $rule) {
                if ($rule === 'required' || (is_string($rule) && str_starts_with($rule, 'required:'))) {
                    $hasRequired = true;
                    break;
                }
            }
            if (! $hasRequired) {
                array_unshift($rules, 'required');
            }
        }

        return $rules;
    }

    public function hasRules(): bool
    {
        return $this->rules !== [] || $this->required;
    }
}
