@props(['table'])

@php
    $sort = $table->sort['column'] ?? null;
    $dir = $table->sort['direction'] ?? null;
    $filterableFields = method_exists($table, 'filterableFields')
        ? $table->filterableFields()
        : array_values(array_filter($table->fields, fn ($f) => $f->isFilterable()));
    $groups = method_exists($table, 'groupedFilterableFields')
        ? $table->groupedFilterableFields()
        : null;
    $hasQb = false;
    if ($table->resource && method_exists($table->resource, 'qbSchema')) {
        $qbSchema = $table->resource->qbSchema();
        $hasQb = ! empty($qbSchema['fields']);
    }
@endphp

<form
    method="GET"
    action="{{ request()->url() }}"
    class="d-flex {{ $groups === null ? 'align-items-center flex-wrap gap-2' : 'flex-column gap-3' }} py-2"
    data-tables-search-form="{{ $table->key }}"
    data-tables-filter-bar="{{ $table->key }}"
    @if ($groups !== null) data-tables-filter-bar-grouped="1" @endif
>
    @if ($table->currentView !== null)
        <input type="hidden" name="view" value="{{ $table->currentView }}">
    @endif
    @if ($sort !== null)
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
    @endif

    @if ($groups === null)
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

        @if ($hasQb)
            <x-tables.qb-button :table="$table"/>
        @endif

        {{ $slot }}

        @isset($right)
            <div class="ms-auto d-flex align-items-center gap-2">{{ $right }}</div>
        @endisset
    @else
        <div class="d-flex align-items-center flex-wrap gap-2">
            <input
                type="search"
                name="q"
                value="{{ $table->search ?? '' }}"
                placeholder="Поиск…"
                aria-label="Поиск"
                class="form-control form-control-sm"
                style="width:280px;"
            >

            @if ($hasQb)
                <x-tables.qb-button :table="$table"/>
            @endif

            {{ $slot }}

            @isset($right)
                <div class="ms-auto d-flex align-items-center gap-2">{{ $right }}</div>
            @endisset
        </div>

        <div class="tables-filter-groups" data-tables-filter-groups="{{ $table->key }}">
            @foreach ($groups as $group)
                <x-tables.filter-group
                    :group="$group"
                    :active-filters="$table->activeFilters"
                    :resource-key="$table->key"
                />
            @endforeach
        </div>
    @endif
</form>
