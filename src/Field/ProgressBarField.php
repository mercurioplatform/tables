<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ProgressBarField extends NumberField
{
    protected null|int|Closure $capacity = null;

    protected null|int|float $lowThreshold = null;

    protected null|int|float $highThreshold = null;

    protected ?Closure $colorUsing = null;

    protected string $barWidth = '80px';

    protected bool $withValue = true;

    public function capacity(int|Closure|null $cap): static
    {
        $this->capacity = $cap;

        return $this;
    }

    public function lowThreshold(int|float $value): static
    {
        $this->lowThreshold = $value;

        return $this;
    }

    public function highThreshold(int|float $value): static
    {
        $this->highThreshold = $value;

        return $this;
    }

    public function colorUsing(Closure $fn): static
    {
        $this->colorUsing = $fn;

        return $this;
    }

    public function barWidth(string $width): static
    {
        $this->barWidth = $width;

        return $this;
    }

    public function withValue(bool $value = true): static
    {
        $this->withValue = $value;

        return $this;
    }

    protected function defaultSchemaType(): string
    {
        return 'progress_bar';
    }

    protected function defaultFormatHints(): array
    {
        return array_merge(parent::defaultFormatHints(), [
            'capacity' => $this->capacity instanceof Closure ? 'closure' : $this->capacity,
            'low_threshold' => $this->lowThreshold,
            'high_threshold' => $this->highThreshold,
            'bar_width' => $this->barWidth,
            'with_value' => $this->withValue,
            'color_using_closure' => $this->colorUsing !== null,
        ]);
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        if ($value === null) {
            return parent::renderDefault($value, $row);
        }

        $num = (float) $value;
        $color = $this->resolveColor($num, $row);
        $cap = $this->resolveCapacity($row);

        if ($cap === null || $cap <= 0) {
            $inner = parent::renderDefault($value, $row);
            if ($color !== null) {
                return new HtmlString(
                    '<span class="text-'.e($color).'">'.$inner->toHtml().'</span>'
                );
            }

            return $inner;
        }

        $pct = max(0.0, min(100.0, ($num / $cap) * 100));
        $pctStr = number_format($pct, 2, '.', '');
        $colorClass = $color ?? 'secondary';

        $valueHtml = $this->withValue
            ? parent::renderDefault($value, $row)->toHtml()
            : '';

        return new HtmlString(
            '<span class="d-inline-flex align-items-center gap-2">'
            .'<div class="progress" role="progressbar" aria-valuenow="'.e((string) $num)
            .'" aria-valuemin="0" aria-valuemax="'.e((string) $cap)
            .'" style="height: 6px; width: '.e($this->barWidth).'">'
            .'<div class="progress-bar bg-'.e($colorClass).'" style="width: '.$pctStr.'%"></div>'
            .'</div>'
            .($valueHtml !== '' ? '<span>'.$valueHtml.'</span>' : '')
            .'</span>'
        );
    }

    protected function resolveColor(float $num, ?Model $row): ?string
    {
        if ($this->colorUsing !== null) {
            $kind = (string) ($this->colorUsing)($num, $row);

            return $kind === '' ? null : $kind;
        }

        if ($this->lowThreshold === null && $this->highThreshold === null) {
            return null;
        }

        if ($this->lowThreshold !== null && $num <= $this->lowThreshold) {
            return 'danger';
        }
        if ($this->highThreshold !== null && $num >= $this->highThreshold) {
            return 'success';
        }

        return 'warning';
    }

    protected function resolveCapacity(?Model $row): ?int
    {
        if ($this->capacity instanceof Closure) {
            $resolved = ($this->capacity)($row);

            return $resolved === null ? null : (int) $resolved;
        }

        return $this->capacity;
    }
}
