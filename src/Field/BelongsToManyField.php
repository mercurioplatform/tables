<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Mercurio\Tables\Filter\Operator;

class BelongsToManyField extends Field
{
    protected string $relation;

    protected string $displayKey = 'name';

    protected string $relatedKey = 'id';

    protected int $previewLimit = 3;

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

    public function relatedKey(string $key): static
    {
        $this->relatedKey = $key;

        return $this;
    }

    public function previewLimit(int $n): static
    {
        $this->previewLimit = max(0, $n);

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

    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    public function getFilterColumn(): string
    {
        return $this->relation;
    }

    protected function defaultFilterAutocomplete(): bool
    {
        return true;
    }

    protected function defaultSchemaType(): string
    {
        return 'belongs_to_many';
    }

    protected function defaultFormatHints(): array
    {
        return [
            'relation' => $this->relation,
            'display_key' => $this->displayKey,
            'related_key' => $this->relatedKey,
            'preview_limit' => $this->previewLimit,
        ];
    }

    public function isFilterMultiple(?Operator $op = null): bool
    {
        return true;
    }

    public function applyFilter(Builder $query, Operator $op, mixed $value): bool
    {
        if (! in_array($op, [Operator::In, Operator::NotIn], true)) {
            return false;
        }

        $ids = is_array($value) ? $value : [$value];
        $ids = array_values(array_filter($ids, fn ($v) => $v !== null && $v !== ''));

        if ($ids === []) {
            return true;
        }

        $relation = $this->relation;
        $relatedColumn = str_contains($this->relatedKey, '.')
            ? $this->relatedKey
            : $relation.'.'.$this->relatedKey;

        if ($op === Operator::In) {
            $query->whereHas($relation, function (Builder $qq) use ($relatedColumn, $ids): void {
                $qq->whereIn($relatedColumn, $ids);
            });
        } else {
            $query->whereDoesntHave($relation, function (Builder $qq) use ($relatedColumn, $ids): void {
                $qq->whereIn($relatedColumn, $ids);
            });
        }

        return true;
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

    protected function renderDefault(mixed $value, mixed $row): Htmlable
    {
        if ($row === null) {
            return new HtmlString(e($this->emptyText));
        }

        $related = $row->{$this->relation} ?? null;
        if ($related === null) {
            return new HtmlString(e($this->emptyText));
        }

        $labels = collect($related)
            ->take($this->previewLimit)
            ->map(fn ($item) => $item->{$this->displayKey} ?? null)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();

        if ($labels === []) {
            return new HtmlString(e($this->emptyText));
        }

        return new HtmlString(e(implode(', ', $labels)));
    }

    public function exportValue(mixed $value, mixed $row = null): string
    {
        if ($row === null) {
            return '';
        }

        $related = $row->{$this->relation} ?? null;
        if ($related === null) {
            Log::warning('tables.export.relation_not_loaded', [
                'field' => $this->name,
                'relation' => $this->relation,
            ]);

            return '';
        }

        if (! $related instanceof Collection) {
            return '';
        }

        return $related
            ->map(fn ($m) => (string) ($m->{$this->displayKey} ?? ''))
            ->filter(fn ($s) => $s !== '')
            ->implode(', ');
    }
}
