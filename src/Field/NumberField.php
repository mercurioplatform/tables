<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class NumberField extends Field
{
    protected int $decimals = 0;

    protected string $decimalSeparator = ',';

    protected string $thousandsSeparator = ' ';

    protected string $emptyText = '—';

    protected ?string $editStep = null;

    protected null|int|float $editMin = null;

    protected null|int|float $editMax = null;

    public function decimals(int $n): static
    {
        $this->decimals = $n;

        return $this;
    }

    public function separators(string $decimal, string $thousands): static
    {
        $this->decimalSeparator = $decimal;
        $this->thousandsSeparator = $thousands;

        return $this;
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    public function editStep(string $step): static
    {
        $this->editStep = $step;

        return $this;
    }

    public function editMin(int|float $min): static
    {
        $this->editMin = $min;

        return $this;
    }

    public function editMax(int|float $max): static
    {
        $this->editMax = $max;

        return $this;
    }

    public function getEditStep(): ?string
    {
        return $this->editStep;
    }

    public function getEditMin(): null|int|float
    {
        return $this->editMin;
    }

    public function getEditMax(): null|int|float
    {
        return $this->editMax;
    }

    public function getEditInputType(): ?string
    {
        return $this->isCellEditEnabled() ? 'number' : null;
    }

    protected function defaultEditRules(?Model $row = null): array
    {
        $rules = ['numeric'];
        if ($this->editMin !== null) {
            $rules[] = 'min:'.$this->editMin;
        }
        if ($this->editMax !== null) {
            $rules[] = 'max:'.$this->editMax;
        }

        return $rules;
    }

    protected function defaultFilterPopoverType(): string
    {
        return 'range';
    }

    protected function defaultQbValueType(): string
    {
        return 'number';
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        if ($value === null) {
            return new HtmlString(e($this->emptyText));
        }

        return new HtmlString(e(number_format(
            (float) $value,
            $this->decimals,
            $this->decimalSeparator,
            $this->thousandsSeparator
        )));
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $this->decimals, $this->decimalSeparator, '');
    }
}
