@php
    $resource = $table->resource;
    $subtitle = $resource ? $resource->subtitle($table->paginator->total()) : null;
@endphp

<p class="ap-page-head__sub @if($subtitle === null) d-none @endif" data-tables-subtitle>{{ $subtitle ?? '' }}</p>
<div data-tables-summary>
    @if ($table->summary !== null)
        <x-tables::summary :summary="$table->summary"/>
    @endif
</div>
<x-tables::saved-views :table="$table"/>
<x-tables::filter-bar :table="$table">
    <x-slot:right>
        <x-tables::export-button :table="$table"/>
        <x-tables::prefs-popover :table="$table"/>
    </x-slot:right>
</x-tables::filter-bar>
<x-tables::table-root :table="$table"/>
