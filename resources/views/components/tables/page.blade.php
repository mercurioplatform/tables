@props([
    'table',
    'bulkAction' => '',
])

<div data-tables-page="{{ $table->key }}" data-tables-total="{{ $table->paginator->total() }}">
    {{ $beforePageHead ?? '' }}

    {{ $afterPageHead ?? '' }}

    {{ $beforeSavedViews ?? '' }}

    @if (count($table->savedViews) > 0)
        <x-tables.saved-views :table="$table"/>
    @endif

    {{ $afterSavedViews ?? '' }}

    @isset($summary)
        {{ $summary }}
    @elseif ($table->summary !== null)
        <x-tables.summary :summary="$table->summary"/>
    @endif

    {{ $beforeFilterBar ?? '' }}

    <x-tables.filter-bar :table="$table">
        {{ $filterBar ?? '' }}

        <x-slot:right>
            @isset($filterBarRight)
                {{ $filterBarRight }}
            @endisset
            <x-tables.export-button :table="$table"/>
            <x-tables.prefs-popover :table="$table"/>
        </x-slot:right>
    </x-tables.filter-bar>

    {{ $afterFilterBar ?? '' }}

    @if (count($table->bulkActions) > 0)
        <x-tables.bulk-bar :table="$table" :action="$bulkAction"/>
    @endif

    {{ $beforeTable ?? '' }}

    <x-tables.table-root :table="$table"/>

    {{ $afterTable ?? '' }}

    {{ $afterPagination ?? '' }}

    @if ($table->resource && method_exists($table->resource, 'qbSchema') && ! empty($table->resource->qbSchema()['fields']))
        <x-tables.qb-offcanvas :table="$table"/>
    @endif

    @if ($table->hasRowActionForms())
        <x-tables.row-action-offcanvas :table="$table"/>
    @endif

    @if ($table->hasBulkActionForms())
        <x-tables.bulk-action-offcanvas :table="$table"/>
    @endif
</div>
