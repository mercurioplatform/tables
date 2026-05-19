<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

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

    protected function defaultFilterPopoverType(): string
    {
        return 'daterange';
    }

    protected function defaultQbValueType(): string
    {
        return 'date';
    }

    protected function defaultSchemaType(): string
    {
        return 'date';
    }

    protected function defaultFormatHints(): array
    {
        return [
            'mode' => $this->mode,
            'format' => $this->format,
        ];
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
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

    public function exportValue(mixed $value, mixed $row = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $carbon = $value instanceof Carbon ? $value : Carbon::parse((string) $value);
        } catch (Throwable $e) {
            Log::warning('tables.export.bad_date', [
                'field' => $this->name,
                'value' => is_scalar($value) ? (string) $value : gettype($value),
                'error' => $e->getMessage(),
            ]);

            return '';
        }

        $format = $this->mode === self::MODE_ABSOLUTE ? $this->format : 'Y-m-d H:i:s';

        return $carbon->format($format);
    }
}
