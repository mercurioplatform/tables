<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Two-line cell with independent main/sub formatters.
 *
 * @see Field::subline() для шортката когда main = $value колонки.
 *
 * Не использовать Field::subline() поверх TwoLineField — Field::render()
 * обернёт результат renderDefault() в ещё один flex-column.
 */
class TwoLineField extends Field
{
    protected ?Closure $main = null;

    protected ?Closure $sub = null;

    protected bool $subMono = false;

    protected string $emptyText = '—';

    public function main(Closure $fn): static
    {
        $this->main = $fn;

        return $this;
    }

    public function sub(Closure $fn): static
    {
        $this->sub = $fn;

        return $this;
    }

    public function subMono(bool $value = true): static
    {
        $this->subMono = $value;

        return $this;
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        $mainText = $this->main !== null
            ? ($this->main)($value, $row)
            : $value;

        if ($mainText === null || $mainText === '') {
            return new HtmlString(e($this->emptyText));
        }

        $subText = $this->sub !== null
            ? ($this->sub)($value, $row)
            : null;

        $subClasses = 'text-muted small'.($this->subMono ? ' font-monospace' : '');

        $html = '<div class="d-flex flex-column">'
            .'<div>'.e((string) $mainText).'</div>';

        if ($subText !== null && (string) $subText !== '') {
            $html .= '<div class="'.$subClasses.'">'.e((string) $subText).'</div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    public function exportValue(mixed $value, mixed $row = null): string
    {
        $mainText = $this->main !== null
            ? ($this->main)($value, $row)
            : $value;

        $main = $mainText === null ? '' : (string) $mainText;

        $sub = $this->sub !== null && $row !== null
            ? (string) ($this->sub)($value, $row)
            : '';

        if ($main === '' && $sub === '') {
            return '';
        }
        if ($sub === '') {
            return $main;
        }
        if ($main === '') {
            return $sub;
        }

        return $main.' / '.$sub;
    }
}
