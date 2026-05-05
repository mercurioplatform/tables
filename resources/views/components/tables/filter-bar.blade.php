@props(['table'])

@php
    $sort = $table->sort['column'] ?? null;
    $dir = $table->sort['direction'] ?? null;
    $filterableFields = method_exists($table, 'filterableFields')
        ? $table->filterableFields()
        : array_values(array_filter($table->fields, fn ($f) => $f->isFilterable()));
@endphp

<form
    method="GET"
    action="{{ request()->url() }}"
    class="d-flex align-items-center flex-wrap gap-2 py-2"
    data-tables-search-form="{{ $table->key }}"
    data-tables-filter-bar="{{ $table->key }}"
>
    @if ($table->currentView !== null)
        <input type="hidden" name="view" value="{{ $table->currentView }}">
    @endif
    @if ($sort !== null)
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
    @endif

    <input
        type="search"
        name="q"
        value="{{ $table->search ?? '' }}"
        placeholder="Поиск…"
        aria-label="Поиск"
        class="form-control form-control-sm"
        style="width:280px;"
    >

    @foreach ($filterableFields as $field)
        <x-tables.filter-chip
            :field="$field"
            :current="$table->activeFilters[$field->name] ?? null"
            :resource-key="$table->key"
        />
    @endforeach

    @if ($table->resource && method_exists($table->resource, 'qbSchema'))
        @php $qbSchema = $table->resource->qbSchema(); @endphp
        @if (! empty($qbSchema['fields']))
            <x-tables.qb-button :table="$table"/>
        @endif
    @endif

    {{ $slot }}

    @isset($right)
        <div class="ms-auto d-flex align-items-center gap-2">{{ $right }}</div>
    @endisset
</form>
