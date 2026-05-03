<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class MoneyField extends NumberField
{
    protected int $divisor = 100;
    protected string $currency = '₽';
    protected string $currencyPosition = 'after';

    public function divisor(int $divisor): static
    {
        $this->divisor = $divisor;
        return $this;
    }

    public function currency(string $sign, string $position = 'after'): static
    {
        $this->currency = $sign;
        $this->currencyPosition = $position;
        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        if ($value === null) {
            return new HtmlString(e($this->emptyText));
        }
        $amount = (float) $value / max(1, $this->divisor);
        $formatted = number_format(
            $amount,
            $this->decimals,
            $this->decimalSeparator,
            $this->thousandsSeparator
        );
        $output = $this->currencyPosition === 'before'
            ? $this->currency.' '.$formatted
            : $formatted.' '.$this->currency;
        return new HtmlString(e($output));
    }
}
