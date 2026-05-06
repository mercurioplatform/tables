@props(['table'])

<div class="offcanvas offcanvas-end ap-confirm-preview-offcanvas"
     tabindex="-1"
     id="tables-confirm-preview-offcanvas"
     aria-labelledby="tables-confirm-preview-offcanvas-title"
     data-bs-backdrop="static">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="tables-confirm-preview-offcanvas-title">…</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Закрыть"></button>
    </div>
    <div class="offcanvas-body" data-tables-confirm-preview-body></div>
    <div class="offcanvas-footer border-top p-3 d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">Отмена</button>
        <button type="button" class="btn btn-primary btn-sm" data-tables-confirm-preview-submit>Подтвердить</button>
    </div>
</div>
