@props([
    'table',
    'action' => '',
])

@php
    $actions = $table->bulkActions;
    $resource = $table->resource ?? null;
    $baseName = $resource?->routeBaseName();
    if ($baseName === null) {
        $current = (string) (\Illuminate\Support\Facades\Route::currentRouteName() ?? '');
        $baseName = $current !== '' ? \Illuminate\Support\Str::beforeLast($current, '.') : '';
    }
@endphp

@if ($actions !== [])
    <form
        class="ap-bulk"
        method="POST"
        action="{{ $action }}"
        data-tables-bulk-form="{{ $table->key }}"
        hidden
    >
        @csrf
        <input type="hidden" name="action" data-tables-bulk-action-input value="">
        <input type="hidden" name="ids" data-tables-bulk-ids-input value="">

        <span class="ap-bulk__count">
            Выбрано <span data-tables-bulk-count>0</span>
        </span>
        <span class="ap-bulk__sep">·</span>
        <span class="ap-bulk__actions">
            @foreach ($actions as $bulk)
                @php
                    $btnClass = match ($bulk->getVariant()) {
                        'danger'  => 'btn btn-sm btn-danger',
                        'primary' => 'btn btn-sm btn-primary',
                        default   => 'btn btn-sm btn-outline-secondary',
                    };
                    $kind = $bulk->getKind();
                    $confirm = $kind === 'confirm' ? ($bulk->getConfirmText() ?? 'Подтвердить?') : null;
                @endphp
                @if ($kind === 'form')
                    @php
                        $formUrl = $baseName !== '' ? route($baseName.'.bulk_action_form', ['action' => $bulk->name]) : '#';
                        $tooltip = $bulk->getTooltip() ?? $bulk->label;
                    @endphp
                    <button
                        type="button"
                        class="{{ $btnClass }}"
                        data-tables-bulk-action-form-button
                        data-action="{{ $bulk->name }}"
                        data-action-label="{{ $bulk->label }}"
                        data-form-url="{{ $formUrl }}"
                        data-reload-after="{{ $bulk->shouldReloadAfterSubmit() ? '1' : '0' }}"
                        title="{{ $tooltip }}"
                    >
                        @if ($bulk->getIcon()) <i class="bi {{ $bulk->getIcon() }}"></i> @endif
                        {{ $bulk->label }}
                    </button>
                @else
                    <button
                        type="submit"
                        class="{{ $btnClass }}"
                        data-tables-bulk-action="{{ $bulk->name }}"
                        @if ($confirm !== null) data-tables-bulk-confirm="{{ $confirm }}" @endif
                    >
                        @if ($bulk->getIcon()) <i class="bi {{ $bulk->getIcon() }}"></i> @endif
                        {{ $bulk->label }}
                    </button>
                @endif
            @endforeach
        </span>
        <button
            type="button"
            class="ap-bulk__close btn btn-sm btn-link p-0 ms-auto"
            data-tables-bulk-clear
            aria-label="Закрыть"
        >
            <i class="bi bi-x-lg"></i>
        </button>
    </form>
@endif
