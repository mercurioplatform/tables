@props(['table'])

<div class="offcanvas offcanvas-end ap-bulk-action-offcanvas"
     tabindex="-1"
     id="tables-bulk-action-offcanvas"
     aria-labelledby="tables-bulk-action-offcanvas-title"
     data-bs-backdrop="static">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="tables-bulk-action-offcanvas-title">…</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{ __('tables::shell.close') }}"></button>
    </div>
    <div class="offcanvas-body" data-tables-bulk-action-body></div>
</div>
