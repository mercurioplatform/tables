@props([
    'table',
    'action' => '',
])

@php
    $actions = $table->bulkActions;
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
                    $confirm = $bulk->getKind() === 'confirm' ? ($bulk->getConfirmText() ?? 'Подтвердить?') : null;
                @endphp
                <button
                    type="submit"
                    class="{{ $btnClass }}"
                    data-tables-bulk-action="{{ $bulk->name }}"
                    @if ($confirm !== null) data-tables-bulk-confirm="{{ $confirm }}" @endif
                >
                    @if ($bulk->getIcon()) <i class="bi {{ $bulk->getIcon() }}"></i> @endif
                    {{ $bulk->label }}
                </button>
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
