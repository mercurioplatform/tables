@props(['table'])

@php
    $base = $table->resource?->routeBaseName();
    if ($base === null || $base === '') {
        $current = (string) (\Illuminate\Support\Facades\Route::currentRouteName() ?? '');
        $base = $current !== '' ? \Illuminate\Support\Str::beforeLast($current, '.') : '';
    }
    $exportUrl = $base !== '' ? route($base.'.export', request()->query()) : '#';
    $label = (string) config('tables.export.button_label', 'Экспорт');
    $icon = (string) config('tables.export.button_icon', 'bi-download');
@endphp

<a
    href="{{ $exportUrl }}"
    class="btn btn-sm btn-outline-secondary ap-export-button"
    data-tables-export
    title="{{ $label }} (CSV, текущий фильтр и колонки)"
    aria-label="{{ $label }}"
>
    <i class="bi {{ $icon }}" aria-hidden="true"></i>
    <span class="ms-1 d-none d-md-inline">{{ $label }}</span>
</a>
