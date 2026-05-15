@props([
    'table',
    'bulkAction' => '',
])

@php
    $cellUpdateUrlTemplate = null;
    if ($table->hasAnyEditableFields()) {
        $base = $table->resource?->routeBaseName();
        if ($base !== null && $base !== '' && \Illuminate\Support\Facades\Route::has($base.'.cell_update')) {
            $cellUpdateUrlTemplate = route($base.'.cell_update', ['id' => '__id__', 'field' => '__field__']);
        }
    }
@endphp

<div
    data-tables-page="{{ $table->key }}"
    data-tables-total="{{ $table->paginator->total() }}"
    @if ($cellUpdateUrlTemplate) data-cell-update-url-template="{{ $cellUpdateUrlTemplate }}" @endif
>
    {{ $beforePageHead ?? '' }}

    {{ $afterPageHead ?? '' }}

    <div data-tables-summary>
        @isset($summary)
            {{ $summary }}
        @elseif ($table->summary !== null)
            <x-tables.summary :summary="$table->summary"/>
        @endif
    </div>

    {{ $beforeSavedViews ?? '' }}

    @if (count($table->savedViews) > 0)
        <x-tables.saved-views :table="$table"/>
    @endif

    {{ $afterSavedViews ?? '' }}

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

    @if ($table->hasConfirmPreviews())
        <x-tables.confirm-preview-offcanvas :table="$table"/>
    @endif

    @if ($table->hasAnyEditableFields())
        <x-tables.cell-edit-templates/>
    @endif
</div>
