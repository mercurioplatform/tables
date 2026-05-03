<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class BelongsToField extends Field
{
    protected string $relation;
    protected string $displayKey = 'name';
    protected ?string $foreignKey = null;
    protected ?Closure $optionsResolver = null;
    protected string $emptyText = '—';

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);
        $this->relation = $name;
    }

    public function relation(string $name): static
    {
        $this->relation = $name;
        return $this;
    }

    public function displayKey(string $key): static
    {
        $this->displayKey = $key;
        return $this;
    }

    public function foreignKey(string $column): static
    {
        $this->foreignKey = $column;
        return $this;
    }

    public function options(Closure $resolver): static
    {
        $this->optionsResolver = $resolver;
        return $this;
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;
        return $this;
    }

    public function getRelation(): string { return $this->relation; }
    public function getDisplayKey(): string { return $this->displayKey; }
    public function getForeignKey(): ?string { return $this->foreignKey; }
    public function getOptionsResolver(): ?Closure { return $this->optionsResolver; }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        if ($row === null) {
            return new HtmlString(e($this->emptyText));
        }
        $related = $row->{$this->relation} ?? null;
        if ($related === null) {
            return new HtmlString(e($this->emptyText));
        }
        $display = $related->{$this->displayKey} ?? null;
        if ($display === null || $display === '') {
            return new HtmlString(e($this->emptyText));
        }
        return new HtmlString(e((string) $display));
    }
}
