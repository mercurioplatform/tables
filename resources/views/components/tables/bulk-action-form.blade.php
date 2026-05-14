@props(['action', 'ids' => [], 'idsCount' => 0, 'submitUrl'])

<form method="POST"
      action="{{ $submitUrl }}"
      data-tables-bulk-action-submit
      data-bulk-action-name="{{ $action->name }}"
      class="ap-bulk-action-form">
    @csrf
    <input type="hidden" name="action" value="{{ $action->name }}">
    <input type="hidden" name="ids" value="{{ implode(',', $ids) }}">

    <div class="ap-bulk-action-form__preview alert alert-info py-2 small mb-3">
        {!! __('tables::bulk.apply_to_summary', ['count' => '<strong>'.e((string) $idsCount).'</strong>']) !!}
    </div>

    {{ $slot }}

    <div class="ap-bulk-action-form__footer mt-3 d-flex gap-2 justify-content-end">
        {{ $footer ?? '' }}
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">{{ __('tables::shell.cancel') }}</button>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('tables::bulk.apply_button') }}</button>
    </div>
</form>
