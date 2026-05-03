<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class TextField extends Field
{
    protected string $emptyText = '—';

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
        return new HtmlString(e((string) $value));
    }
}
