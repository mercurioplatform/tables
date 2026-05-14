@php
    /** @var string $kind */
    /** @var \Mercurio\Tables\Action\BulkAction|\Mercurio\Tables\Action\RowAction $action */
    /** @var string $submitUrl */
    /** @var string $html */
    $ids = $ids ?? [];
@endphp

<div class="ap-confirm-preview"
     data-submit-url="{{ $submitUrl }}"
     data-kind="{{ $kind }}"
     @if ($kind === 'bulk') data-ids="{{ implode(',', $ids) }}" @endif
     data-confirm-text="{{ $action->getConfirmText() ?: __('tables::confirm.default_label') }}"
     data-confirm-variant="{{ $action->getVariant() }}">
    {!! $html !!}
</div>
