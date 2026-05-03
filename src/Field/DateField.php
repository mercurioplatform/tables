<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class DateField extends Field
{
    public const MODE_RELATIVE = 'relative';
    public const MODE_ABSOLUTE = 'absolute';

    protected string $mode = self::MODE_RELATIVE;
    protected string $format = 'd.m.Y';
    protected string $emptyText = '—';

    public function relative(): static
    {
        $this->mode = self::MODE_RELATIVE;
        return $this;
    }

    public function format(string $format): static
    {
        $this->mode = self::MODE_ABSOLUTE;
        $this->format = $format;
        return $this;
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;
        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        if ($value === null || $value === '') {
            return new HtmlString(e($this->emptyText));
        }
        $carbon = $value instanceof Carbon ? $value : Carbon::parse((string) $value);
        $output = $this->mode === self::MODE_RELATIVE
            ? $carbon->diffForHumans()
            : $carbon->format($this->format);
        return new HtmlString(e($output));
    }
}
