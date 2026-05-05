@props(['field', 'current' => null])

@php
    use Mercurio\Tables\Filter\Operator;
    use Illuminate\Support\Facades\Route;

    $type = $field->getFilterPopoverType();
    $operators = $field->getFilterableOperators();
    $currentOp = $current?->operator?->value ?? ($operators[0]->value ?? null);
    $currentValue = $current?->value;
    $displayValue = $current !== null ? $field->denormalizeFilterValue($currentValue) : null;

    $rangeOps = ['between', 'not_between'];
    $isCurrentRange = in_array($currentOp, $rangeOps, true);

    $rawMin = is_array($displayValue) ? ($displayValue[0] ?? '') : '';
    $rawMax = is_array($displayValue) ? ($displayValue[1] ?? '') : '';
    $rawSingle = is_scalar($displayValue) ? $displayValue : '';

    $isAutocomplete = $type === 'autocomplete';
    $autocompleteUrl = null;
    $autocompleteSelected = [];
    $autocompleteMultiple = false;

    if ($isAutocomplete) {
        $currentRouteName = Route::currentRouteName();
        if ($currentRouteName) {
            $optionsBase = preg_replace('/\.[^.]+$/', '', $currentRouteName);
            try {
                $autocompleteUrl = route($optionsBase.'.options');
            } catch (\Throwable $e) {
                $autocompleteUrl = url()->current().rtrim((string) config('tables.route_options_suffix', '/options'), ' ');
            }
        } else {
            $autocompleteUrl = url()->current().(string) config('tables.route_options_suffix', '/options');
        }

        $opEnum = $currentOp ? Operator::tryFrom($currentOp) : null;
        $autocompleteMultiple = $field->isFilterMultiple($opEnum);

        $selectedIds = [];
        if ($currentValue !== null) {
            $rawSelected = is_array($currentValue) ? $currentValue : [$currentValue];
            $selectedIds = array_values(array_filter(
                array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $rawSelected),
                fn ($v) => $v !== null && $v !== '',
            ));
        }
        $autocompleteSelected = $selectedIds !== [] ? $field->resolveOptionsByIds($selectedIds) : [];
    }
@endphp

<div
    class="tables-filter-popover"
    data-tables-filter-popover-form
    data-field="{{ $field->name }}"
    @if ($isAutocomplete)
        data-tables-autocomplete
        data-tables-autocomplete-url="{{ $autocompleteUrl }}"
        data-tables-autocomplete-field="{{ $field->name }}"
        data-tables-autocomplete-multiple="{{ $autocompleteMultiple ? '1' : '0' }}"
        data-tables-autocomplete-min-chars="{{ (int) config('tables.autocomplete_min_chars', 0) }}"
        data-tables-autocomplete-debounce="{{ (int) config('tables.autocomplete_debounce_ms', 250) }}"
    @endif
>
    <div class="tables-filter-popover__op">
        <select name="op" class="form-select form-select-sm">
            @foreach ($operators as $op)
                <option value="{{ $op->value }}" @selected($op->value === $currentOp)>{{ $field->operatorLabel($op) }}</option>
            @endforeach
        </select>
    </div>

    <div class="tables-filter-popover__value" data-tables-filter-popover-value>
        @switch($type)
            @case('select')
                <div class="tables-filter-popover__select">
                    @forelse ($field->getFilterOptions() as $value => $optionLabel)
                        @php
                            $checked = is_array($currentValue)
                                && in_array((string) $value, array_map('strval', $currentValue), true);
                        @endphp
                        <label class="form-check tables-filter-popover__check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                name="value[]"
                                value="{{ $value }}"
                                @checked($checked)
                            >
                            <span class="form-check-label">{{ $optionLabel }}</span>
                        </label>
                    @empty
                        <div class="text-muted small px-1 py-2">Нет вариантов</div>
                    @endforelse
                </div>
                @break

            @case('range')
                <div class="d-flex gap-2 tables-filter-popover__range" data-tables-filter-range>
                    <input
                        type="number"
                        step="any"
                        name="value[min]"
                        class="form-control form-control-sm tables-filter-popover__range-input"
                        data-range-input="min"
                        placeholder="От"
                        value="{{ $rawMin }}"
                        @class(['d-none' => ! $isCurrentRange])
                    >
                    <input
                        type="number"
                        step="any"
                        name="value[max]"
                        class="form-control form-control-sm tables-filter-popover__range-input"
                        data-range-input="max"
                        placeholder="До"
                        value="{{ $rawMax }}"
                        @class(['d-none' => ! $isCurrentRange])
                    >
                    <input
                        type="number"
                        step="any"
                        name="value[single]"
                        class="form-control form-control-sm tables-filter-popover__range-input"
                        data-range-input="single"
                        placeholder="Значение"
                        value="{{ $rawSingle }}"
                        @class(['d-none' => $isCurrentRange])
                    >
                </div>
                @break

            @case('daterange')
                @php
                    $from = is_array($currentValue) ? ($currentValue['from'] ?? $currentValue[0] ?? '') : '';
                    $to = is_array($currentValue) ? ($currentValue['to'] ?? $currentValue[1] ?? '') : '';
                @endphp
                <div class="d-flex gap-2 tables-filter-popover__daterange">
                    <input
                        type="date"
                        name="value[from]"
                        class="form-control form-control-sm"
                        value="{{ $from }}"
                    >
                    <input
                        type="date"
                        name="value[to]"
                        class="form-control form-control-sm"
                        value="{{ $to }}"
                    >
                </div>
                @break

            @case('autocomplete')
                <div class="tables-filter-popover__autocomplete">
                    <div
                        class="tables-filter-popover__selected"
                        data-tables-autocomplete-selected-list
                    >
                        @foreach ($autocompleteSelected as $val => $lbl)
                            <span class="tables-filter-popover__chip" data-value="{{ $val }}">
                                <span class="tables-filter-popover__chip-label">{{ $lbl }}</span>
                                <button
                                    type="button"
                                    class="tables-filter-popover__chip-remove"
                                    data-tables-autocomplete-chip-remove
                                    aria-label="Удалить"
                                >×</button>
                                <input type="hidden" name="value[]" value="{{ $val }}">
                            </span>
                        @endforeach
                    </div>
                    <input
                        type="text"
                        class="form-control form-control-sm tables-filter-popover__input"
                        data-tables-autocomplete-input
                        placeholder="Найти…"
                        autocomplete="off"
                    >
                    <ul
                        class="tables-filter-popover__results"
                        data-tables-autocomplete-results
                        hidden
                    ></ul>
                </div>
                @break

            @case('text')
            @default
                <input
                    type="text"
                    name="value"
                    class="form-control form-control-sm"
                    value="{{ is_scalar($currentValue) ? $currentValue : '' }}"
                    placeholder="Введите значение"
                >
        @endswitch
    </div>

    <div class="tables-filter-popover__actions">
        <button
            type="button"
            class="btn btn-sm btn-link tables-filter-popover__clear"
            data-tables-filter-clear
            data-field="{{ $field->name }}"
        >Очистить</button>
        <button
            type="button"
            class="btn btn-sm btn-primary tables-filter-popover__apply"
            data-tables-filter-apply
            data-field="{{ $field->name }}"
        >Применить</button>
    </div>
</div>
