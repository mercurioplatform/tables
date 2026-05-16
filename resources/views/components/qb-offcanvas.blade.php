@props(['table'])

@php
    $widthClass = config('tables.qb_offcanvas_width', 'qb-offcanvas-md');
    $schema = $table->resource && method_exists($table->resource, 'qbSchema')
        ? $table->resource->qbSchema()
        : ['fields' => []];
    $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stateJson = $table->qb['json'] ?? '';
@endphp

<div
    class="offcanvas offcanvas-end {{ $widthClass }}"
    tabindex="-1"
    id="tables-qb-{{ $table->key }}"
    aria-labelledby="tables-qb-{{ $table->key }}-label"
    data-tables-qb-table-key="{{ $table->key }}"
>
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title m-0" id="tables-qb-{{ $table->key }}-label">
            {{ __((string) config('tables.qb_button_label', 'tables::qb.button_label')) }}
        </h5>
        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            aria-label="{{ __('tables::shell.close') }}"
        ></button>
    </div>

    <div class="offcanvas-body">
        <div
            data-tables-qb-root
            data-tables-qb-table-key="{{ $table->key }}"
            data-tables-qb-schema="{{ $schemaJson }}"
            data-tables-qb-state="{{ $stateJson }}"
        ></div>
    </div>

    <div class="offcanvas-footer border-top p-3 d-flex gap-2 justify-content-end">
        <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            data-tables-qb-reset
        >
            {{ __('tables::qb.clear_button') }}
        </button>
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-bs-dismiss="offcanvas"
        >
            {{ __('tables::shell.cancel') }}
        </button>
        <button
            type="button"
            class="btn btn-sm btn-primary"
            data-tables-qb-apply
        >
            {{ __('tables::qb.apply_button') }}
        </button>
    </div>
</div>
