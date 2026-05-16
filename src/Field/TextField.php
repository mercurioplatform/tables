<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class TextField extends Field
{
    protected string $emptyText = '—';

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    protected function defaultFilterPopoverType(): string
    {
        return 'text';
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        if ($value === null || $value === '') {
            return new HtmlString(e($this->emptyText));
        }

        return new HtmlString(e((string) $value));
    }
}
