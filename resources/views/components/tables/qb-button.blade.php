@props(['table', 'label' => null])

@php
    $resolvedLabel = $label ?? __((string) config('tables.qb_button_label', 'tables::qb.button_label'));
    $atomCount = (int) ($table->qb['atoms'] ?? 0);
@endphp

<div class="d-inline-flex align-items-center gap-1" data-tables-qb-trigger="{{ $table->key }}">
    <button
        type="button"
        class="btn btn-sm btn-outline-secondary tables-qb-toggle"
        data-bs-toggle="offcanvas"
        data-bs-target="#tables-qb-{{ $table->key }}"
        aria-controls="tables-qb-{{ $table->key }}"
    >
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span class="ms-1">{{ $resolvedLabel }}</span>
        @if ($atomCount > 0)
            <span class="badge text-bg-primary ms-1" data-tables-qb-counter>{{ $atomCount }}</span>
        @endif
    </button>
    @if ($atomCount > 0)
        <button
            type="button"
            class="btn btn-sm btn-link text-danger p-0"
            data-tables-qb-clear
            title="{{ __('tables::qb.clear_extended_aria') }}"
            aria-label="{{ __('tables::qb.clear_extended_aria') }}"
        >
            <i class="bi bi-x-circle" aria-hidden="true"></i>
        </button>
    @endif
</div>
