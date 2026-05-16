@props(['table'])

@php
    $base = $table->resource?->routeBaseName();
    if ($base === null || $base === '') {
        $current = (string) (\Illuminate\Support\Facades\Route::currentRouteName() ?? '');
        $base = $current !== '' ? \Illuminate\Support\Str::beforeLast($current, '.') : '';
    }
    $label = __((string) config('tables.export.button_label', 'tables::export.button_label'));
    $icon = (string) config('tables.export.button_icon', 'bi-download');

    $registry = app(\Mercurio\Tables\Export\ExportWriterRegistry::class);
    $available = $registry->formats();
    $formatLabels = (array) config('tables.export.formats', []);
    $defaultFormat = (string) config('tables.export.default_format', 'csv');

    $formats = [];
    foreach ($formatLabels as $fmt => $lbl) {
        if (! is_string($fmt) || ! in_array($fmt, $available, true)) {
            continue;
        }
        $formats[$fmt] = __((string) $lbl);
    }
    if ($formats === []) {
        $formats[$defaultFormat] = strtoupper($defaultFormat);
    }

    $singleFormat = count($formats) === 1;
    $defaultLabel = $formats[$defaultFormat] ?? reset($formats);
    $tooltip = __('tables::export.button_tooltip', ['label' => $label, 'format' => $defaultLabel]);

    $exportUrlFor = function (string $format) use ($base) {
        if ($base === '') {
            return '#';
        }
        $params = request()->query();
        if ($format !== '') {
            $params['format'] = $format;
        }

        return route($base.'.export', $params);
    };
@endphp

@if ($singleFormat)
    @php $only = array_key_first($formats); @endphp
    <a
        href="{{ $exportUrlFor((string) $only) }}"
        class="btn btn-sm btn-outline-secondary ap-export-button"
        data-tables-export
        title="{{ $tooltip }}"
        aria-label="{{ $label }}"
    >
        <i class="bi {{ $icon }}" aria-hidden="true"></i>
        <span class="ms-1 d-none d-md-inline">{{ $label }}</span>
    </a>
@else
    <div class="btn-group ap-export-button-group">
        <a
            href="{{ $exportUrlFor($defaultFormat) }}"
            class="btn btn-sm btn-outline-secondary ap-export-button"
            data-tables-export
            data-tables-export-format="{{ $defaultFormat }}"
            title="{{ $tooltip }}"
            aria-label="{{ $label }}"
        >
            <i class="bi {{ $icon }}" aria-hidden="true"></i>
            <span class="ms-1 d-none d-md-inline">{{ $label }}</span>
        </a>
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            aria-label="{{ __('tables::export.format_dropdown_aria') }}"
        >
            <span class="visually-hidden">{{ __('tables::export.format_dropdown_aria') }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            @foreach ($formats as $fmt => $fmtLabel)
                <li>
                    <a
                        href="{{ $exportUrlFor((string) $fmt) }}"
                        class="dropdown-item"
                        data-tables-export
                        data-tables-export-format="{{ $fmt }}"
                    >{{ $fmtLabel }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
