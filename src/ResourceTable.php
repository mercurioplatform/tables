<?php

namespace Mercurio\Tables;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Page\EmptyState;
use Mercurio\Tables\Page\HeaderAction;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\Page;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

final class ResourceTable
{
    private const BUILTIN_FILTER_GROUP_LABELS = [
        '' => 'Прочее',
        'business' => 'Бизнес',
        'meta' => 'Мета',
        'system' => 'Система',
    ];

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
        public readonly Page $page,
        public readonly Capabilities $capabilities,
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
        return collect($this->page->rows);
    }

    /**
     * Deprecated proxy на {@see $page} для смягчения миграции host-published
     * Blade-шаблонов, которые читали `$table->paginator->total()` и т.п.
     * {@see Page} реализует LengthAwarePaginator-совместимый shim, так что
     * большинство вызовов продолжают работать без правок.
     *
     * @deprecated Используйте {@see $page}. Будет удалено в v3.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'paginator') {
            return $this->page;
        }

        return null;
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
     * Group filterable fields for accordion rendering.
     *
     * Returns null when the count is at or below the resource threshold
     * (signal to render the inline single-row layout).
     *
     * Boundary is inclusive: count <= threshold → null.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     fields: array<int, Field>,
     *     activeCount: int,
     * }>|null
     */
    public function groupedFilterableFields(): ?array
    {
        $fields = $this->filterableFields();
        $threshold = $this->resource?->filterGroupThreshold() ?? 10;

        if (count($fields) <= $threshold) {
            return null;
        }

        $groups = [];
        foreach ($fields as $field) {
            $key = $field->getFilterGroup() ?? '';
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $this->resolveFilterGroupLabel($key),
                    'fields' => [],
                    'activeCount' => 0,
                ];
            }
            $groups[$key]['fields'][] = $field;
            if (isset($this->activeFilters[$field->name])) {
                $groups[$key]['activeCount']++;
            }
        }

        return array_values($groups);
    }

    private function resolveFilterGroupLabel(string $key): string
    {
        $custom = $this->resource?->filterGroupLabels() ?? [];
        if (isset($custom[$key])) {
            return $custom[$key];
        }

        if (isset(self::BUILTIN_FILTER_GROUP_LABELS[$key])) {
            return self::BUILTIN_FILTER_GROUP_LABELS[$key];
        }

        return Str::headline($key);
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

        return $this->page->perPage();
    }

    /**
     * @return array<int, HeaderAction>
     */
    public function shellHeaderActions(): array
    {
        $actions = $this->resource?->headerActions() ?? [];

        if ($this->resource === null || ! $this->resource->actionHistoryEnabled()) {
            return $actions;
        }

        if (! $this->capabilities->mutate) {
            return $actions;
        }

        $base = $this->resource->routeBaseName();
        if ($base === null || $base === '' || ! Route::has($base.'.action_log')) {
            return $actions;
        }

        $url = route($base.'.action_log');
        $offcanvasId = $this->actionLogOffcanvasId();
        $label = (string) __((string) config('tables.action_log.header_action_label', 'tables::action_log.header_action_label'));
        $icon = (string) config('tables.action_log.header_action_icon', 'bi-clock-history');

        $history = HeaderAction::make($label, '#'.$offcanvasId)
            ->icon($icon)
            ->variant('outline-secondary')
            ->attrs([
                'data-bs-toggle' => 'offcanvas',
                'data-bs-target' => '#'.$offcanvasId,
                'data-tables-action-log-trigger' => $offcanvasId,
                'data-tables-action-log-url' => $url,
                'role' => 'button',
            ]);

        return [$history, ...$actions];
    }

    public function actionLogOffcanvasId(): string
    {
        $key = $this->resource?->key() ?? $this->key;

        return 'tables-action-log-'.Str::slug(str_replace('.', '-', $key));
    }
}
