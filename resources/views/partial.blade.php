@php
    $resource = $table->resource;
    $subtitle = $resource ? $resource->subtitle($table->paginator->total()) : null;
@endphp

<p class="ap-page-head__sub @if($subtitle === null) d-none @endif" data-tables-subtitle>{{ $subtitle ?? '' }}</p>
<x-tables.saved-views :table="$table"/>
<x-tables.filter-bar :table="$table">
    <x-slot:right>
        <x-tables.export-button :table="$table"/>
        <x-tables.prefs-popover :table="$table"/>
    </x-slot:right>
</x-tables.filter-bar>
<x-tables.table-root :table="$table"/>
