@props(['action', 'submitUrl'])

<form method="POST"
      action="{{ $submitUrl }}"
      data-tables-row-action-submit
      class="ap-row-action-form">
    @csrf
    {{ $slot }}
    <div class="ap-row-action-form__footer mt-3 d-flex gap-2 justify-content-end">
        {{ $footer ?? '' }}
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">{{ __('tables::shell.cancel') }}</button>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('tables::shell.save') }}</button>
    </div>
</form>
