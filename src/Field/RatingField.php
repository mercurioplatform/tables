<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class RatingField extends Field
{
    protected int $max = 5;

    protected int $precision = 1;

    protected string $style = 'stars';

    protected bool $showValue = true;

    protected bool $emptyAsDash = true;

    protected ?Closure $colorUsing = null;

    public function max(int $n): static
    {
        $this->max = max(1, $n);

        return $this;
    }

    public function precision(int $n): static
    {
        $this->precision = max(0, $n);

        return $this;
    }

    public function style(string $style): static
    {
        $this->style = $style === 'bar' ? 'bar' : 'stars';

        return $this;
    }

    public function showValue(bool $value = true): static
    {
        $this->showValue = $value;

        return $this;
    }

    public function emptyAsDash(bool $value = true): static
    {
        $this->emptyAsDash = $value;

        return $this;
    }

    public function colorUsing(Closure $fn): static
    {
        $this->colorUsing = $fn;

        return $this;
    }

    protected function defaultSchemaType(): string
    {
        return 'rating';
    }

    protected function defaultFormatHints(): array
    {
        return [
            'max' => $this->max,
            'precision' => $this->precision,
            'style' => $this->style,
            'show_value' => $this->showValue,
            'empty_as_dash' => $this->emptyAsDash,
            'color_using_closure' => $this->colorUsing !== null,
        ];
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        $num = is_numeric($value) ? (float) $value : 0.0;

        if ($num <= 0 && $this->emptyAsDash) {
            return new HtmlString('<span class="u-mute">—</span>');
        }

        $color = $this->colorUsing !== null
            ? (string) ($this->colorUsing)($num, $row)
            : 'warning';
        if ($color === '') {
            $color = 'warning';
        }

        $valueLabel = $this->showValue
            ? '<span class="small u-mute ms-2">'
                .e(number_format($num, $this->precision, ',', ''))
                .'</span>'
            : '';

        if ($this->style === 'bar') {
            $pct = $this->max > 0
                ? max(0, min(100, ($num / $this->max) * 100))
                : 0;
            $pctStr = number_format($pct, 2, '.', '');

            return new HtmlString(
                '<span class="tables-rating tables-rating--bar d-inline-flex align-items-center">'
                .'<div class="progress" role="progressbar" aria-valuenow="'.e((string) $num)
                .'" aria-valuemin="0" aria-valuemax="'.e((string) $this->max).'" style="height: 6px; width: 80px">'
                .'<div class="progress-bar bg-'.e($color).'" style="width: '.$pctStr.'%"></div>'
                .'</div>'
                .$valueLabel
                .'</span>'
            );
        }

        $full = (int) floor($num);
        $hasHalf = ($num - $full) >= 0.5;
        $empty = $this->max - $full - ($hasHalf ? 1 : 0);
        $empty = max(0, $empty);

        $stars = str_repeat(
            '<i class="bi bi-star-fill text-'.e($color).'"></i>',
            min($full, $this->max),
        );
        if ($hasHalf && $full < $this->max) {
            $stars .= '<i class="bi bi-star-half text-'.e($color).'"></i>';
        }
        $stars .= str_repeat('<i class="bi bi-star u-mute"></i>', $empty);

        return new HtmlString(
            '<span class="tables-rating">'.$stars.$valueLabel.'</span>'
        );
    }
}
