import jQuery from 'jquery';
import { tablesT } from './i18n.js';

const $ = jQuery;

function escapeHtml(str) {
    return $('<div>').text(String(str ?? '')).html();
}

export function tablesConfirm({
    title,
    message = '',
    confirmText,
    cancelText,
    confirmVariant = 'primary',
    icon = 'bi-question-circle',
} = {}) {
    if (title === undefined) title = tablesT('confirm.title_default');
    if (confirmText === undefined) confirmText = tablesT('confirm.confirm_default');
    if (cancelText === undefined) cancelText = tablesT('confirm.cancel_default');
    return new Promise((resolve) => {
        const Bootstrap = window.bootstrap;
        if (!Bootstrap || !Bootstrap.Modal) {
            resolve(window.confirm(message || title));
            return;
        }

        const id = 'tables-confirm-' + Math.random().toString(36).slice(2, 9);
        const html = `
<div class="modal fade ap-tables-confirm" id="${id}" tabindex="-1" aria-hidden="true" aria-labelledby="${id}-title">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow">
      <div class="modal-body p-4 text-center">
        <div class="ap-tables-confirm__icon mb-3">
          <i class="bi ${escapeHtml(icon)}"></i>
        </div>
        <h6 class="mb-2" id="${id}-title">${escapeHtml(title)}</h6>
        ${message ? `<p class="text-muted small mb-0">${escapeHtml(message)}</p>` : ''}
      </div>
      <div class="modal-footer border-0 pt-0 pb-3 px-3 justify-content-center gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-action="cancel">${escapeHtml(cancelText)}</button>
        <button type="button" class="btn btn-${escapeHtml(confirmVariant)} btn-sm px-3" data-action="confirm" autofocus>${escapeHtml(confirmText)}</button>
      </div>
    </div>
  </div>
</div>`;

        const $modal = $(html).appendTo(document.body);
        const el = $modal.get(0);
        const inst = Bootstrap.Modal.getOrCreateInstance(el);
        let result = false;

        $modal.on('click', '[data-action="confirm"]', () => {
            result = true;
            inst.hide();
        });
        $modal.on('click', '[data-action="cancel"]', () => {
            result = false;
            inst.hide();
        });
        $modal.on('shown.bs.modal', () => {
            $modal.find('[data-action="confirm"]').trigger('focus');
        });
        $modal.on('hidden.bs.modal', () => {
            $modal.remove();
            resolve(result);
        });

        inst.show();
    });
}
