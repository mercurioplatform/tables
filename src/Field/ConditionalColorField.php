<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ConditionalColorField extends NumberField
{
    protected ?Closure $colorUsing = null;

    public function colorUsing(Closure $fn): static
    {
        $this->colorUsing = $fn;

        return $this;
    }

    protected function defaultSchemaType(): string
    {
        return 'conditional_color';
    }

    protected function defaultFormatHints(): array
    {
        return array_merge(parent::defaultFormatHints(), [
            'color_using_closure' => $this->colorUsing !== null,
        ]);
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        $inner = parent::renderDefault($value, $row);

        if ($value === null || $this->colorUsing === null) {
            return $inner;
        }

        $kind = (string) ($this->colorUsing)($value, $row);

        if ($kind === '') {
            return $inner;
        }

        return new HtmlString(
            '<span class="text-'.e($kind).'">'.$inner->toHtml().'</span>'
        );
    }
}
