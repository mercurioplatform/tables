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
    $label = (string) config('tables.user_prefs.popover_button_label', 'Настроить таблицу');
    $icon = (string) config('tables.user_prefs.popover_button_icon', 'bi-gear');
@endphp

<button
    type="button"
    class="btn btn-sm btn-outline-secondary ap-prefs-popover-trigger"
    data-tables-prefs-trigger="{{ $table->key }}"
    data-save-url="{{ $saveUrl }}"
    data-reset-url="{{ $resetUrl }}"
    title="{{ $label }}"
    aria-label="{{ $label }}"
>
    <i class="bi {{ $icon }}" aria-hidden="true"></i>
</button>

<template data-tables-prefs-template="{{ $table->key }}">
    <div data-tables-prefs-popover-marker>
        <form class="ap-prefs-popover-form" data-tables-prefs-form>
            <input type="hidden" name="_token" value="{{ csrf_token() }}">

            <div class="ap-prefs-popover-error alert alert-danger small mb-2 d-none" data-tables-prefs-error></div>

            <div class="ap-prefs-popover-section mb-3">
                <div class="form-label small fw-semibold mb-2">Колонки</div>
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
                <div class="form-label small fw-semibold mb-2">Плотность</div>
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
                            Просторная
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
                            Компактная
                        </label>
                    </div>
                </div>
            </div>

            <div class="ap-prefs-popover-section mb-3">
                <label for="prefs-per-page-{{ $table->key }}" class="form-label small fw-semibold mb-2">На странице</label>
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
                    Сбросить
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-tables-prefs-cancel>
                        Отмена
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" data-tables-prefs-submit>
                        Применить
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>
