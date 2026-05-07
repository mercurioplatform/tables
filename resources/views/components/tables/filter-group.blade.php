@props(['group', 'activeFilters' => [], 'resourceKey' => 'tables'])

@php
    $groupKey = $group['key'] === '' ? '_other' : $group['key'];
    $idSafeResourceKey = preg_replace('/[^A-Za-z0-9_-]/', '-', $resourceKey);
    $idSafeGroupKey = preg_replace('/[^A-Za-z0-9_-]/', '-', $groupKey);
    $bodyId = 'tables-fg-'.$idSafeResourceKey.'-'.$idSafeGroupKey;
    $hasActive = ($group['activeCount'] ?? 0) > 0;
@endphp

<div
    class="tables-filter-group {{ $hasActive ? 'tables-filter-group--has-active' : '' }}"
    data-tables-filter-group="{{ $group['key'] }}"
    data-resource-key="{{ $resourceKey }}"
>
    <button
        type="button"
        class="tables-filter-group__toggle"
        data-bs-toggle="collapse"
        data-bs-target="#{{ $bodyId }}"
        aria-expanded="true"
        aria-controls="{{ $bodyId }}"
    >
        <i class="bi bi-chevron-down tables-filter-group__caret" aria-hidden="true"></i>
        <span class="tables-filter-group__label">{{ $group['label'] }}</span>
        @if ($hasActive)
            <span class="tables-filter-group__badge">{{ $group['activeCount'] }}</span>
        @endif
    </button>

    <div id="{{ $bodyId }}" class="collapse show tables-filter-group__body">
        <div class="d-flex flex-wrap gap-2">
            @foreach ($group['fields'] as $field)
                <x-tables.filter-chip
                    :field="$field"
                    :current="$activeFilters[$field->name] ?? null"
                    :resource-key="$resourceKey"
                />
            @endforeach
        </div>
    </div>
</div>
