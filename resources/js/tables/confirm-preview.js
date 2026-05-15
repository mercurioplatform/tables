import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { submitBulkAjax } from './bulk.js';
import { tablesT } from './i18n.js';
import { cloneSharedTemplate } from './shared-templates.js';
import { showOffcanvas } from './offcanvas.js';
import { ATTRS, sel } from './data-attrs.js';

const $ = jQuery;
const OFFCANVAS_ID = 'tables-confirm-preview-offcanvas';

function variantToClass(variant) {
    return ({
        danger: 'btn-danger',
        warning: 'btn-warning',
        success: 'btn-success',
        primary: 'btn-primary',
        secondary: 'btn-secondary',
    })[variant] || 'btn-primary';
}

const SUBMIT_VARIANT_CLASSES = 'btn-danger btn-warning btn-success btn-primary btn-secondary';

function openPreviewOffcanvas({ url, label, onConfirm }) {
    const oc = document.getElementById(OFFCANVAS_ID);
    if (!oc) return false;

    const $oc = $(oc);
    $oc.find('.offcanvas-title').text(label || '');
    const $body = $oc.find(sel(ATTRS.CONFIRM_PREVIEW_BODY));
    const $submit = $oc.find(sel(ATTRS.CONFIRM_PREVIEW_SUBMIT));

    $body.empty();
    const $spinner = cloneSharedTemplate('loading-spinner', $body);
    if ($spinner) {
        $spinner.find(sel(ATTRS.LOADING_TEXT)).text(tablesT('confirm.preview.loading'));
        $body.append($spinner);
    }
    $submit
        .prop('disabled', true)
        .removeClass(SUBMIT_VARIANT_CLASSES)
        .addClass('btn-primary')
        .text(tablesT('confirm.preview.confirm_default'));

    const inst = showOffcanvas(oc);
    if (!inst) return false;

    tablesAjax({
        url,
        method: 'GET',
        dataType: 'html',
    })
        .done(function (html) {
            $body.html(html);
            const $payload = $body.find('.ap-confirm-preview').first();
            const confirmText = $payload.attr('data-confirm-text') || tablesT('confirm.preview.confirm_default');
            const variant = $payload.attr('data-confirm-variant') || 'primary';
            $submit
                .removeClass(SUBMIT_VARIANT_CLASSES)
                .addClass(variantToClass(variant))
                .text(confirmText)
                .prop('disabled', false);
        })
        .fail(function (jqXHR) {
            const msg = jqXHR.status === 401 || jqXHR.status === 419
                ? tablesT('confirm.preview.session_expired')
                : tablesT('confirm.preview.load_failed');
            $body.empty();
            const $alert = cloneSharedTemplate('alert-danger', $body);
            if ($alert) {
                $alert.text(msg);
                $body.append($alert);
            }
        });

    function handleConfirm() {
        $submit.off('click', handleConfirm);
        $oc.off('hidden.bs.offcanvas', cleanup);
        inst.hide();
        onConfirm();
    }

    function cleanup() {
        $submit.off('click', handleConfirm);
        $oc.off('hidden.bs.offcanvas', cleanup);
    }

    $submit.on('click', handleConfirm);
    $oc.on('hidden.bs.offcanvas', cleanup);

    return true;
}

// Bulk: перехват click на submit-кнопке action'а с data-tables-bulk-preview-url.
$(document).on('click', sel(ATTRS.BULK_FORM) + ' ' + sel(ATTRS.BULK_PREVIEW_URL), function (e) {
    const $btn = $(this);
    const $form = $btn.closest(sel(ATTRS.BULK_FORM));
    const url = $btn.attr(ATTRS.BULK_PREVIEW_URL);
    const label = $btn.attr('data-action-label') || $btn.text().trim();
    const action = $btn.attr(ATTRS.BULK_ACTION) || '';
    const isQueued = $btn.attr(ATTRS.BULK_QUEUED) === '1';
    const resourceKey = $form.attr(ATTRS.BULK_FORM) || '';

    const $page = $form.closest(sel(ATTRS.PAGE));
    const $scope = $page.find(sel(ATTRS.BULK_SCOPE)).first();
    const ids = [];
    $scope.find(sel(ATTRS.ROW_CHECKBOX) + ':checked').each(function () {
        const id = $(this).attr(ATTRS.ROW_ID);
        if (id) ids.push(String(id));
    });

    if (ids.length === 0) {
        // Без выбора — пусть submit-handler в bulk.js сам блокирует.
        return;
    }

    e.preventDefault();

    const previewUrl = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'ids=' + encodeURIComponent(ids.join(','));
    const opened = openPreviewOffcanvas({
        url: previewUrl,
        label,
        onConfirm: () => {
            $form.find(sel(ATTRS.BULK_ACTION_INPUT)).val(action);
            $form.find(sel(ATTRS.BULK_IDS_INPUT)).val(ids.join(','));

            if (isQueued && resourceKey) {
                $form.data('pendingAction', action);
                $form.data('pendingLabel', label);
                $form.data('pendingQueued', '1');
                $form.data('pendingConfirm', '');
                submitBulkAjax($form, resourceKey);
                return;
            }

            // Прямой submit, минуя bulk.js submit-handler с window.confirm fallback.
            HTMLFormElement.prototype.submit.call($form.get(0));
        },
    });

    if (!opened) {
        // Bootstrap недоступен — пусть отработает обычный submit-flow.
        $form.trigger('submit');
    }
});

// Row: перехват click на confirm-кнопке action'а с data-tables-row-preview-url.
// stopImmediatePropagation подавляет row-action-confirm-handler в row-actions.js
// (его селектор `[data-tables-row-action-confirm]:not([data-tables-row-preview-url])` уже
// исключает preview-actions, но stopImmediatePropagation добавляет страховку при изменении порядка).
$(document).on('click', sel(ATTRS.ROW_PREVIEW_URL), function (e) {
    const $btn = $(this);
    const $form = $btn.closest('form');
    const url = $btn.attr(ATTRS.ROW_PREVIEW_URL);
    const label = $btn.attr('data-action-label') || $btn.attr('aria-label') || '';

    e.preventDefault();
    e.stopImmediatePropagation();

    const opened = openPreviewOffcanvas({
        url,
        label,
        onConfirm: () => {
            HTMLFormElement.prototype.submit.call($form.get(0));
        },
    });

    if (!opened && window.confirm($btn.attr('data-confirm-text') || tablesT('row_actions.default_confirm'))) {
        $form.trigger('submit');
    }
});
