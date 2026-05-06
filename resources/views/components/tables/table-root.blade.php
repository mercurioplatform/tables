@props(['table'])

@php
    $fields = $table->visibleFields();
    $sortCol = $table->sort['column'] ?? null;
    $sortDir = $table->sort['direction'] ?? 'asc';
    $hasBulk = count($table->bulkActions) > 0;
    $hasRowActions = $table->hasRowActions();
    $colspan = max(1, count($fields) + ($hasBulk ? 1 : 0) + ($hasRowActions ? 1 : 0));
@endphp

<div data-tables-root data-tables-key="{{ $table->key }}" data-tables-total="{{ $table->paginator->total() }}" class="tables-density-{{ $table->density }}">
    <div class="card overflow-hidden">
        <table class="table table-hover align-middle mb-0" @if ($hasBulk) data-tables-bulk-scope="{{ $table->key }}" @endif>
            <thead>
                <tr>
                    @if ($hasBulk)
                        <th class="ap-table__chk">
                            <div class="form-check m-0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    data-tables-select-all
                                    aria-label="Выбрать все строки"
                                >
                            </div>
                        </th>
                    @endif
                    @foreach ($fields as $field)
                        @php
                            $isCurrent = $sortCol === $field->name;
                            $nextDir = ($isCurrent && $sortDir === 'asc') ? 'desc' : 'asc';
                            $headerAlign = match ($field->getAlign()) {
                                'right'  => 'text-end',
                                'center' => 'text-center',
                                default  => '',
                            };
                            $icon = ! $field->isSortable()
                                ? null
                                : ($isCurrent
                                    ? ($sortDir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down')
                                    : 'bi-arrow-down-up text-muted');
                        @endphp
                        <th @class([$headerAlign => $headerAlign !== ''])>
                            @if ($field->isSortable())
                                <a href="{{ request()->fullUrlWithQuery(['sort' => $field->name, 'dir' => $nextDir, 'page' => null]) }}"
                                   class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                    <span>{{ $field->label }}</span>
                                    <i class="bi {{ $icon }}" aria-hidden="true"></i>
                                </a>
                            @else
                                {{ $field->label }}
                            @endif
                        </th>
                    @endforeach
                    @if ($hasRowActions)
                        <th class="ap-table__more text-end">
                            <span class="visually-hidden">Действия</span>
                        </th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($table->rows() as $row)
                    <tr @if ($hasBulk) data-tables-row="{{ data_get($row, 'id') }}" @endif>
                        @if ($hasBulk)
                            <td class="ap-table__chk">
                                <div class="form-check m-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        data-tables-row-checkbox
                                        data-tables-row-id="{{ data_get($row, 'id') }}"
                                        aria-label="Выбрать строку"
                                    >
                                </div>
                            </td>
                        @endif
                        @foreach ($fields as $field)
                            <x-tables.cell :field="$field" :row="$row"/>
                        @endforeach
                        @if ($hasRowActions)
                            <td class="ap-table__more text-end">
                                <x-tables.row-actions :table="$table" :row="$row"/>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colspan }}" class="text-center py-5">
                            <x-tables.empty-state/>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($table->paginator->hasPages())
        <div class="d-flex justify-content-end mt-3">
            <x-tables.pagination :paginator="$table->paginator"/>
        </div>
    @endif
</div>
