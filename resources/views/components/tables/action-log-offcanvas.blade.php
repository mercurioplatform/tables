@props(['id'])

<div
    class="offcanvas offcanvas-end tables-action-log"
    tabindex="-1"
    id="{{ $id }}"
    data-tables-action-log
    aria-labelledby="{{ $id }}-label"
>
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="{{ $id }}-label">
            <i class="bi bi-clock-history"></i> История действий
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" data-tables-action-log-body>
        <div class="text-center text-muted py-5" data-tables-action-log-loading>
            <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
            <span class="ms-2">Загрузка…</span>
        </div>
    </div>
</div>
