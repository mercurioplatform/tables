@props(['action', 'submitUrl'])

<form method="POST"
      action="{{ $submitUrl }}"
      data-tables-row-action-submit
      class="ap-row-action-form">
    @csrf
    {{ $slot }}
    <div class="ap-row-action-form__footer mt-3 d-flex gap-2 justify-content-end">
        {{ $footer ?? '' }}
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">Отмена</button>
        <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
    </div>
</form>
