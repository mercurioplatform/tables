<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Mercurio\Tables\Filter\Operator;

class BelongsToField extends Field
{
    protected string $relation;

    protected string $displayKey = 'name';

    protected ?string $foreignKey = null;

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

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function getDisplayKey(): string
    {
        return $this->displayKey;
    }

    public function getForeignKey(): ?string
    {
        return $this->foreignKey;
    }

    public function getFilterColumn(): string
    {
        return $this->foreignKey ?? ($this->name.'_id');
    }

    public function getEditableColumn(): string
    {
        $spec = $this->getCellEditSpec();
        if ($spec !== null && $spec->column !== null) {
            return $spec->column;
        }

        return $this->foreignKey ?? ($this->name.'_id');
    }

    public function getEditInputType(): ?string
    {
        return $this->isCellEditEnabled() ? 'select' : null;
    }

    protected function defaultEditRules(?Model $row = null): array
    {
        return ['nullable', 'integer'];
    }

    protected function defaultFilterAutocomplete(): bool
    {
        return true;
    }

    public function isFilterMultiple(?Operator $op = null): bool
    {
        if ($op === null) {
            return in_array(Operator::In, $this->filterableOperators, true)
                || in_array(Operator::NotIn, $this->filterableOperators, true);
        }

        return in_array($op, [Operator::In, Operator::NotIn], true);
    }

    public function denormalizeFilterValue(mixed $value): mixed
    {
        if ($value === null) {
            return $value;
        }

        $ids = is_array($value) ? $value : [$value];
        $ids = array_values(array_filter($ids, fn ($v) => $v !== null && $v !== ''));

        if ($ids === []) {
            return $value;
        }

        $labels = $this->resolveOptionsByIds($ids);
        if ($labels === []) {
            return $value;
        }

        return implode(', ', array_map(fn ($l) => (string) $l, array_values($labels)));
    }

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

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        if ($row === null) {
            return '';
        }
        $related = $row->{$this->relation} ?? null;
        if ($related === null) {
            return '';
        }
        $display = $related->{$this->displayKey} ?? null;

        return $display === null ? '' : (string) $display;
    }
}
