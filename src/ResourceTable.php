<?php

namespace Mercurio\Tables;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\View\SavedView;

final class ResourceTable
{
    /**
     * @param  array<int, Field>  $fields
     * @param  array<int, SavedView>  $savedViews
     * @param  array<int, BulkAction>  $bulkActions
     * @param  array<int, mixed>  $rowActions
     * @param  array{column: string, direction: string}|null  $sort
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
    ) {}

    public function rows(): Collection
    {
        return collect($this->paginator->items());
    }

    /**
     * @return array<int, Field>
     */
    public function visibleFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $f) => ! $f->isHidden(),
        ));
    }
}
