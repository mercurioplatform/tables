@props(['table'])

@php
    $base = $table->resource?->routeBaseName();
    if ($base === null || $base === '') {
        $current = (string) (\Illuminate\Support\Facades\Route::currentRouteName() ?? '');
        $base = $current !== '' ? \Illuminate\Support\Str::beforeLast($current, '.') : '';
    }
    $saveUrl = $base !== '' ? route($base.'.save_prefs') : '#';
    $resetUrl = $base !== '' ? route($base.'.reset_prefs') : '#';

    $columns = $table->availablePrefsColumns();
    $current = array_flip($table->effectiveColumnNames());
    $density = $table->density;
    $perPage = $table->effectivePerPage();
    $perPageOptions = (array) config('tables.user_prefs.per_page_options', [15, 25, 50, 100]);
    $label = __((string) config('tables.user_prefs.popover_button_label', 'tables::prefs.popover_button_label'));
    $icon = (string) config('tables.user_prefs.popover_button_icon', 'bi-gear');
@endphp

<div class="dropdown ap-prefs-popover" data-tables-prefs="{{ $table->key }}">
    <button
        type="button"
        class="btn btn-sm btn-outline-secondary ap-prefs-popover__trigger"
        data-bs-toggle="dropdown"
        data-bs-auto-close="outside"
        data-tables-prefs-trigger="{{ $table->key }}"
        data-save-url="{{ $saveUrl }}"
        data-reset-url="{{ $resetUrl }}"
        title="{{ $label }}"
        aria-label="{{ $label }}"
        aria-expanded="false"
    >
        <i class="bi {{ $icon }}" aria-hidden="true"></i>
    </button>

    <div class="dropdown-menu dropdown-menu-end ap-prefs-popover__menu">
        <div class="ap-prefs-popover-form" data-tables-prefs-form>
            <div class="ap-prefs-popover-error alert alert-danger small mb-2 d-none" data-tables-prefs-error></div>

            <div class="ap-prefs-popover-section mb-3">
                <div class="form-label small fw-semibold mb-2">{{ __('tables::prefs.columns_section') }}</div>
                <div class="ap-prefs-popover-columns d-flex flex-column gap-1">
                    @foreach ($columns as $col)
                        <div class="form-check m-0">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="prefs-col-{{ $table->key }}-{{ $col['name'] }}"
                                name="columns[]"
                                value="{{ $col['name'] }}"
                                @checked(isset($current[$col['name']]))
                            >
                            <label class="form-check-label small" for="prefs-col-{{ $table->key }}-{{ $col['name'] }}">
                                {{ $col['label'] }}
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="ap-prefs-popover-section mb-3">
                <div class="form-label small fw-semibold mb-2">{{ __('tables::prefs.density_section') }}</div>
                <div class="d-flex gap-3">
                    <div class="form-check m-0">
                        <input
                            type="radio"
                            class="form-check-input"
                            id="prefs-density-comfortable-{{ $table->key }}"
                            name="density"
                            value="comfortable"
                            @checked($density === 'comfortable')
                        >
                        <label class="form-check-label small" for="prefs-density-comfortable-{{ $table->key }}">
                            {{ __('tables::prefs.density_comfortable') }}
                        </label>
                    </div>
                    <div class="form-check m-0">
                        <input
                            type="radio"
                            class="form-check-input"
                            id="prefs-density-compact-{{ $table->key }}"
                            name="density"
                            value="compact"
                            @checked($density === 'compact')
                        >
                        <label class="form-check-label small" for="prefs-density-compact-{{ $table->key }}">
                            {{ __('tables::prefs.density_compact') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="ap-prefs-popover-section mb-3">
                <label for="prefs-per-page-{{ $table->key }}" class="form-label small fw-semibold mb-2">{{ __('tables::prefs.per_page_label') }}</label>
                <select
                    id="prefs-per-page-{{ $table->key }}"
                    name="per_page"
                    class="form-select form-select-sm"
                >
                    @foreach ($perPageOptions as $opt)
                        <option value="{{ $opt }}" @selected((int) $perPage === (int) $opt)>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ap-prefs-popover-footer d-flex gap-2 justify-content-between align-items-center">
                <button type="button" class="btn btn-link btn-sm text-danger px-0" data-tables-prefs-reset>
                    {{ __('tables::prefs.reset_button') }}
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-tables-prefs-cancel>
                        {{ __('tables::shell.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-tables-prefs-submit>
                        {{ __('tables::prefs.apply_button') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
