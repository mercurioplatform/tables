<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Money cell with optional compare-price + discount percentage.
 *
 * Не использовать Field::subline() — обёртка из Field::render() сломает
 * вертикальную композицию compare/price/percent.
 */
class DiscountedMoneyField extends MoneyField
{
    protected ?Closure $compareUsing = null;

    protected bool $showPercentage = true;

    public function compareUsing(Closure $fn): static
    {
        $this->compareUsing = $fn;

        return $this;
    }

    public function showPercentage(bool $value = true): static
    {
        $this->showPercentage = $value;

        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        if ($value === null) {
            return new HtmlString(e($this->emptyText));
        }

        $compare = $this->compareUsing !== null
            ? ($this->compareUsing)($value, $row)
            : null;

        if ($compare === null || (float) $compare <= (float) $value) {
            return parent::renderDefault($value, $row);
        }

        $priceFormatted = $this->formatAmount((float) $value);
        $compareFormatted = $this->formatAmount((float) $compare);
        $percent = (int) round((1 - ((float) $value / (float) $compare)) * 100);

        $html = '<span class="d-inline-flex flex-column align-items-end">'
            .'<span class="text-decoration-line-through text-muted small">'.e($compareFormatted).'</span>'
            .'<span>'.e($priceFormatted).'</span>';

        if ($this->showPercentage && $percent > 0) {
            $html .= '<span class="text-success small">−'.$percent.'%</span>';
        }

        $html .= '</span>';

        return new HtmlString($html);
    }

    private function formatAmount(float $raw): string
    {
        $amount = $raw / max(1, $this->divisor);
        $formatted = number_format(
            $amount,
            $this->decimals,
            $this->decimalSeparator,
            $this->thousandsSeparator
        );

        return $this->currencyPosition === 'before'
            ? $this->currency.' '.$formatted
            : $formatted.' '.$this->currency;
    }
}
