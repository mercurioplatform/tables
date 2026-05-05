@props(['field', 'current' => null, 'resourceKey' => 'tables'])

@php
    $isActive = $current !== null;
    $popoverId = 'tables-chip-'.$resourceKey.'-'.$field->name;
    $valueLabel = $isActive ? $field->renderFilterValue($current) : '';
    $label = $isActive
        ? ($field->label.': '.$valueLabel)
        : ('+ '.$field->label);
@endphp

<div
    class="tables-chip dropdown {{ $isActive ? 'tables-chip--active' : 'tables-chip--add' }}"
    data-tables-chip
    data-field="{{ $field->name }}"
    data-popover-type="{{ $field->getFilterPopoverType() }}"
>
    <button
        type="button"
        class="tables-chip__btn"
        data-bs-toggle="dropdown"
        data-bs-auto-close="outside"
        aria-expanded="false"
        title="{{ $label }}"
    >
        <span class="tables-chip__label">{{ $label }}</span>
    </button>

    @if ($isActive)
        <button
            type="button"
            class="tables-chip__remove"
            data-tables-chip-remove
            data-field="{{ $field->name }}"
            aria-label="Убрать"
        >
            <i class="bi bi-x" aria-hidden="true"></i>
        </button>
    @endif

    <div class="dropdown-menu tables-chip__popover" id="{{ $popoverId }}">
        <x-tables.filter-popover :field="$field" :current="$current"/>
    </div>
</div>
