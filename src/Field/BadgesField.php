<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class BadgesField extends Field
{
    protected ?Closure $using = null;

    protected bool $subtle = true;

    protected string $gap = '1';

    public function using(Closure $fn): static
    {
        $this->using = $fn;

        return $this;
    }

    public function withoutSubtle(bool $value = true): static
    {
        $this->subtle = ! $value;

        return $this;
    }

    public function gap(string $value): static
    {
        $this->gap = $value;

        return $this;
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        $badges = $this->using !== null
            ? array_filter((array) ($this->using)($value, $row))
            : [];

        if ($badges === []) {
            return new HtmlString('');
        }

        $html = '<span class="d-inline-flex flex-wrap gap-'.e($this->gap).'">';

        foreach ($badges as $badge) {
            if (! is_array($badge) || ! isset($badge['label'], $badge['kind'])) {
                continue;
            }

            $kind = (string) $badge['kind'];
            $classes = $this->subtle
                ? 'badge bg-'.$kind.'-subtle text-'.$kind.'-emphasis'
                : 'badge bg-'.$kind.' text-white';

            $html .= '<span class="'.$classes.'">'.e((string) $badge['label']).'</span>';
        }

        $html .= '</span>';

        return new HtmlString($html);
    }

    public function exportValue(mixed $value, mixed $row = null): string
    {
        if ($this->using === null) {
            return '';
        }

        $badges = (array) ($this->using)($value, $row);
        $labels = [];
        foreach ($badges as $badge) {
            if (! is_array($badge) || ! isset($badge['label'])) {
                continue;
            }
            $label = (string) $badge['label'];
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return implode(', ', $labels);
    }
}
