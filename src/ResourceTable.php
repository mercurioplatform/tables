<?php

namespace Mercurio\Tables;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Page\EmptyState;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

final class ResourceTable
{
    /**
     * @param  array<int, Field>  $fields
     * @param  array<int, SavedView>  $savedViews
     * @param  array<int, BulkAction>  $bulkActions
     * @param  array<int, RowAction>  $rowActions
     * @param  array{column: string, direction: string}|null  $sort
     * @param  array<string, FilterCondition>  $activeFilters
     * @param  array{json: string, atoms: int, depth: int}|null  $qb
     * @param  array<string, int>  $savedViewCounts
     * @param  array<int, string>|null  $effectiveColumns
     */
    public function __construct(
        public readonly string $key,
        public readonly LengthAwarePaginator $paginator,
        public readonly array $fields,
        public readonly array $savedViews,
        public readonly array $bulkActions,
        public readonly array $rowActions,
        public readonly ?array $sort,
        public readonly ?string $currentView,
        public readonly ?string $search,
        public readonly string $density = 'comfortable',
        public readonly ?Summary $summary = null,
        public readonly ?ListResource $resource = null,
        public readonly array $activeFilters = [],
        public readonly ?array $qb = null,
        public readonly array $savedViewCounts = [],
        public readonly ?array $effectiveColumns = null,
        public readonly ?int $perPage = null,
        public readonly ?EmptyState $emptyState = null,
    ) {}

    public function rows(): Collection
    {
        return collect($this->paginator->items());
    }

    public function hasRowActions(): bool
    {
        return $this->rowActions !== [];
    }

    public function hasAnyEditableFields(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->isEditable()) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== null
            || $this->activeFilters !== []
            || $this->qb !== null;
    }

    public function hasRowActionForms(): bool
    {
        foreach ($this->rowActions as $action) {
            if ($action instanceof RowAction && $action->getKind() === 'form') {
                return true;
            }
        }

        return false;
    }

    public function hasBulkActionForms(): bool
    {
        foreach ($this->bulkActions as $action) {
            if ($action instanceof BulkAction && $action->getKind() === 'form') {
                return true;
            }
        }

        return false;
    }

    public function hasConfirmPreviews(): bool
    {
        foreach ($this->bulkActions as $action) {
            if ($action instanceof BulkAction && $action->getKind() === 'confirm' && $action->hasPreview()) {
                return true;
            }
        }

        foreach ($this->rowActions as $action) {
            if ($action instanceof RowAction && $action->getKind() === 'confirm' && $action->hasPreview()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Field>
     */
    public function visibleFields(): array
    {
        if ($this->effectiveColumns !== null) {
            $set = array_flip($this->effectiveColumns);
            $byName = [];
            foreach ($this->fields as $f) {
                if ($f->isOnlyFilterable()) {
                    continue;
                }
                if (isset($set[$f->name])) {
                    $byName[$f->name] = $f;
                }
            }

            $ordered = [];
            foreach ($this->effectiveColumns as $name) {
                if (isset($byName[$name])) {
                    $ordered[] = $byName[$name];
                }
            }

            return $ordered;
        }

        return array_values(array_filter(
            $this->fields,
            fn (Field $f) => ! $f->isHidden() && ! $f->isOnlyFilterable(),
        ));
    }

    /**
     * @return array<int, Field>
     */
    public function filterableFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $f) => $f->isFilterable(),
        ));
    }

    /**
     * @return array<int, array{name: string, label: string, hidden_by_default: bool}>
     */
    public function availablePrefsColumns(): array
    {
        return array_values(array_map(
            fn (Field $f) => [
                'name' => $f->name,
                'label' => $f->label,
                'hidden_by_default' => $f->isHidden(),
            ],
            array_filter($this->fields, fn (Field $f) => ! $f->isOnlyFilterable()),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function effectiveColumnNames(): array
    {
        if ($this->effectiveColumns !== null) {
            return array_values($this->effectiveColumns);
        }

        return array_values(array_map(
            fn (Field $f) => $f->name,
            array_filter(
                $this->fields,
                fn (Field $f) => ! $f->isHidden() && ! $f->isOnlyFilterable(),
            ),
        ));
    }

    public function effectivePerPage(): int
    {
        if ($this->perPage !== null) {
            return $this->perPage;
        }

        return (int) $this->paginator->perPage();
    }
}
