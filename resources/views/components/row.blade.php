@props(['table', 'row'])

@php
    $fields = $table->visibleFields();
    $hasBulk = count($table->bulkActions) > 0 && $table->capabilities->mutate;
    $hasRowActions = $table->hasRowActions();
@endphp

<tr @if ($hasBulk) data-tables-row="{{ data_get($row, 'id') }}" @endif>
    @if ($hasBulk)
        <td class="ap-table__chk">
            <div class="form-check m-0">
                <input
                    class="form-check-input"
                    type="checkbox"
                    data-tables-row-checkbox
                    data-tables-row-id="{{ data_get($row, 'id') }}"
                    aria-label="{{ __('tables::row_actions.select_row_aria') }}"
                >
            </div>
        </td>
    @endif
    @foreach ($fields as $field)
        <x-tables::cell :field="$field" :row="$row" :table="$table"/>
    @endforeach
    @if ($hasRowActions)
        <td class="ap-table__more text-end">
            <x-tables::row-actions :table="$table" :row="$row"/>
        </td>
    @endif
</tr>
