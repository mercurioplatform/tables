<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Mercurio\Tables\Support\Pluralizer;

class RelationCountField extends NumberField
{
    /** @var array{many: string, one: string, few: string}|null */
    protected ?array $plural = null;

    protected bool $withLabel = true;

    protected ?string $iconBefore = null;

    protected bool $emptyAsDash = true;

    public function plural(string $many, string $one, string $few): static
    {
        $this->plural = ['many' => $many, 'one' => $one, 'few' => $few];

        return $this;
    }

    public function withLabel(bool $value = true): static
    {
        $this->withLabel = $value;

        return $this;
    }

    public function iconBefore(?string $icon): static
    {
        $this->iconBefore = $icon;

        return $this;
    }

    public function emptyAsDash(bool $value = true): static
    {
        $this->emptyAsDash = $value;

        return $this;
    }

    protected function defaultSchemaType(): string
    {
        return 'relation_count';
    }

    protected function defaultFormatHints(): array
    {
        return array_merge(parent::defaultFormatHints(), [
            'plural' => $this->plural,
            'with_label' => $this->withLabel,
            'icon_before' => $this->iconBefore,
            'empty_as_dash' => $this->emptyAsDash,
        ]);
    }

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        if (($value === null || (int) $value === 0) && $this->emptyAsDash) {
            return new HtmlString('<span class="u-mute">—</span>');
        }

        $n = (int) $value;
        $numHtml = parent::renderDefault($n, $row)->toHtml();

        $iconHtml = $this->iconBefore !== null && $this->iconBefore !== ''
            ? '<i class="bi '.e($this->iconBefore).' u-mute me-1"></i>'
            : '';

        $labelHtml = '';
        if ($this->plural !== null && $this->withLabel) {
            $word = Pluralizer::ru($n, $this->plural['many'], $this->plural['one'], $this->plural['few']);
            $labelHtml = ' <span class="u-mute small">'.e($word).'</span>';
        }

        return new HtmlString(
            '<span class="d-inline-flex align-items-center">'
            .$iconHtml.$numHtml.$labelHtml
            .'</span>'
        );
    }
}
