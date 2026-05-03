@props(['table'])

@php
    $fields = $table->visibleFields();
    $sortCol = $table->sort['column'] ?? null;
    $sortDir = $table->sort['direction'] ?? 'asc';
@endphp

<div data-tables-root data-tables-key="{{ $table->key }}">
    <div class="card overflow-hidden">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
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
                </tr>
            </thead>
            <tbody>
                @forelse ($table->rows() as $row)
                    <tr>
                        @foreach ($fields as $field)
                            <x-tables.cell :field="$field" :row="$row"/>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max(1, count($fields)) }}" class="text-center py-5">
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
