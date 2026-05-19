<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;

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

    public function normalizeFilterValue(mixed $value): mixed
    {
        $multiplier = max(1, $this->divisor);

        if (is_array($value)) {
            return array_map(
                fn ($v) => $v === null || $v === '' ? null : (int) round(((float) $v) * $multiplier),
                $value,
            );
        }

        if ($value === null || $value === '') {
            return $value;
        }

        return (int) round(((float) $value) * $multiplier);
    }

    protected function defaultSchemaType(): string
    {
        return 'money';
    }

    protected function defaultFormatHints(): array
    {
        return array_merge(parent::defaultFormatHints(), [
            'currency' => $this->currency,
            'divisor' => $this->divisor,
            'position' => $this->currencyPosition,
        ]);
    }

    public function denormalizeFilterValue(mixed $value): mixed
    {
        $divisor = max(1, $this->divisor);

        if (is_array($value)) {
            return array_map(
                fn ($v) => $v === null || $v === '' ? '' : ((float) $v) / $divisor,
                $value,
            );
        }

        if ($value === null || $value === '') {
            return '';
        }

        return ((float) $value) / $divisor;
    }

    public function renderFilterValue(FilterCondition $cond): string
    {
        if (in_array($cond->operator, [Operator::Empty_, Operator::NotEmpty], true)) {
            return $this->operatorLabel($cond->operator);
        }

        $divisor = max(1, $this->divisor);

        $format = function (mixed $cents): string {
            if ($cents === null || $cents === '') {
                return '?';
            }
            $amount = ((float) $cents) / max(1, $this->divisor);

            return number_format(
                $amount,
                $this->decimals,
                $this->decimalSeparator,
                $this->thousandsSeparator,
            );
        };

        $withCurrency = function (string $formatted): string {
            return $this->currencyPosition === 'before'
                ? $this->currency.' '.$formatted
                : $formatted.' '.$this->currency;
        };

        if (in_array($cond->operator, [Operator::Between, Operator::NotBetween], true) && is_array($cond->value)) {
            $a = $format($cond->value[0] ?? null);
            $b = $format($cond->value[1] ?? null);

            return $this->operatorLabel($cond->operator).' '.$withCurrency($a.'–'.$b);
        }

        if (is_array($cond->value)) {
            $parts = array_map($format, $cond->value);

            return $this->operatorLabel($cond->operator).' '.$withCurrency(implode(', ', $parts));
        }

        return $this->operatorLabel($cond->operator).' '.$withCurrency($format($cond->value));
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
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

    public function exportValue(mixed $value, mixed $row = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $amount = (float) $value / max(1, $this->divisor);

        return number_format($amount, $this->decimals, $this->decimalSeparator, '');
    }
}
