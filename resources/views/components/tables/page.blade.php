@props([
    'table',
    'bulkAction' => '',
])

{{ $beforePageHead ?? '' }}

{{ $afterPageHead ?? '' }}

{{ $beforeSavedViews ?? '' }}

@if (count($table->savedViews) > 0)
    <x-tables.saved-views :table="$table"/>
@endif

{{ $afterSavedViews ?? '' }}

{{ $summary ?? '' }}

{{ $beforeFilterBar ?? '' }}

<x-tables.filter-bar :table="$table">
    {{ $filterBar ?? '' }}

    @isset($filterBarRight)
        <x-slot:right>{{ $filterBarRight }}</x-slot:right>
    @endisset
</x-tables.filter-bar>

{{ $afterFilterBar ?? '' }}

@if (count($table->bulkActions) > 0)
    <x-tables.bulk-bar :table="$table" :action="$bulkAction"/>
@endif

{{ $beforeTable ?? '' }}

<x-tables.table-root :table="$table"/>

{{ $afterTable ?? '' }}

{{ $afterPagination ?? '' }}
