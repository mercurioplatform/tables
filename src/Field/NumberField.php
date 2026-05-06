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
