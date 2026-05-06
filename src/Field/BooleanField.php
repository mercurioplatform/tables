<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class BooleanField extends Field
{
    protected string $trueLabel = 'Да';

    protected string $falseLabel = 'Нет';

    protected string $trueKind = 'success';

    protected string $falseKind = 'secondary';

    protected bool $useDot = true;

    public function labels(string $true, string $false): static
    {
        $this->trueLabel = $true;
        $this->falseLabel = $false;

        return $this;
    }

    public function kinds(string $true, string $false): static
    {
        $this->trueKind = $true;
        $this->falseKind = $false;

        return $this;
    }

    public function withoutDot(): static
    {
        $this->useDot = false;

        return $this;
    }

    protected function defaultFilterPopoverType(): string
    {
        return 'select';
    }

    protected function defaultQbValueType(): string
    {
        return 'boolean';
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        $bool = (bool) $value;
        $label = $bool ? $this->trueLabel : $this->falseLabel;
        $kind = $bool ? $this->trueKind : $this->falseKind;
        $dot = $this->useDot ? '<span class="me-1" aria-hidden="true">•</span>' : '';

        return new HtmlString(
            '<span class="badge rounded-pill bg-'.e($kind).'-subtle text-'.e($kind).'-emphasis">'
            .$dot.e($label).'</span>'
        );
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        return ((bool) $value) ? 'да' : 'нет';
    }
}
