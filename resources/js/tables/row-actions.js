import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesConfirm } from './confirm.js';
import { tablesT } from './i18n.js';
import { cloneSharedTemplate } from './shared-templates.js';
import { showOffcanvas, hideOffcanvas } from './offcanvas.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

const OFFCANVAS_ID = 'tables-row-action-offcanvas';

function navigateReload($form) {
    const $page = $form.closest(sel(ATTRS.PAGE));
    if ($page.length === 0) return;
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, {
        detail: { url: window.location.href, push: false },
    }));
}

function clearFieldErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.invalid-feedback' + sel(ATTRS.ROW_ACTION_ERROR)).remove();
}

function renderFieldErrors($form, errors) {
    clearFieldErrors($form);
    if (!errors || typeof errors !== 'object') return;

    Object.keys(errors).forEach(function (name) {
        const messages = Array.isArray(errors[name]) ? errors[name] : [errors[name]];
        const $field = $form.find('[name="' + name + '"]').first();
        if ($field.length === 0) return;
        $field.addClass('is-invalid');
        const $feedback = cloneSharedTemplate('invalid-feedback', $form);
        if (!$feedback) return;
        $feedback.attr(ATTRS.ROW_ACTION_ERROR, '').text(messages.join(' '));
        if ($field.next('.invalid-feedback').length > 0) {
            $field.next('.invalid-feedback').replaceWith($feedback);
        } else {
            $field.after($feedback);
        }
    });
}

$(document).on('click', sel(ATTRS.ROW_ACTION_CONFIRM) + ':not(' + sel(ATTRS.ROW_PREVIEW_URL) + ')', async function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    const $btn = $(this);
    const text = $btn.attr('data-confirm-text') || tablesT('row_actions.default_confirm');
    const ok = await tablesConfirm({
        title: tablesT('confirm.title_default'),
        message: text,
        confirmText: tablesT('confirm.confirm_default'),
        confirmVariant: 'danger',
        icon: 'bi-exclamation-circle',
    });
    if (ok) {
        const $form = $btn.closest('form');
        if ($form.length > 0) {
            HTMLFormElement.prototype.submit.call($form.get(0));
        }
    }
});

$(document).on('click', sel(ATTRS.ROW_ACTION_FORM_BUTTON), function (e) {
    e.preventDefault();
    const $btn = $(this);
    const formUrl = $btn.attr('data-form-url');
    const submitUrl = $btn.attr('data-submit-url');
    const label = $btn.attr('data-action-label') || '';
    const reloadAfter = $btn.attr('data-reload-after') !== '0';

    const oc = document.getElementById(OFFCANVAS_ID);
    if (!oc) return;

    const $oc = $(oc);
    $oc.find('.offcanvas-title').text(label);
    const $body = $oc.find(sel(ATTRS.ROW_ACTION_BODY));
    $body.attr('data-submit-url', submitUrl);
    $body.attr('data-reload-after', reloadAfter ? '1' : '0');
    $body.addClass('is-loading').empty();
    const $spinner = cloneSharedTemplate('loading-spinner', $btn);
    if ($spinner) {
        $spinner.find(sel(ATTRS.LOADING_TEXT)).text(tablesT('row_actions.loading'));
        $body.append($spinner);
    }

    if (!showOffcanvas(oc)) return;

    tablesAjax({
        url: formUrl,
        method: 'GET',
        dataType: 'html',
    }).done(function (html) {
        $body.removeClass('is-loading').html(html);
    }).fail(function (jqXHR) {
        const message = jqXHR.status === 401 || jqXHR.status === 419
            ? tablesT('cell.session_expired')
            : tablesT('row_actions.load_failed');
        $body.removeClass('is-loading').empty();
        const $alert = cloneSharedTemplate('alert-danger', $btn);
        if ($alert) {
            $alert.text(message);
            $body.append($alert);
        }
    });
});

$(document).on('submit', 'form' + sel(ATTRS.ROW_ACTION_SUBMIT), function (e) {
    e.preventDefault();
    const $form = $(this);
    const url = $form.attr('action');
    if (!url) return;

    const $submit = $form.find('button[type="submit"]');
    $submit.prop('disabled', true);
    clearFieldErrors($form);

    const reloadAfter = $form.closest(sel(ATTRS.ROW_ACTION_BODY)).attr('data-reload-after') !== '0';

    tablesAjax({
        url: url,
        method: 'POST',
        data: $form.serialize(),
        headers: { Accept: 'application/json' },
        dataType: 'json',
    }).done(function (data) {
        hideOffcanvas(OFFCANVAS_ID);
        if (reloadAfter) {
            navigateReload($form);
            return;
        }
        if (data?.flash?.error) {
            window.alert(data.flash.error);
        } else if (data?.flash?.warning) {
            window.alert(data.flash.warning);
        }
    }).fail(function (jqXHR) {
        if (jqXHR.status === 422 && jqXHR.responseJSON?.errors) {
            renderFieldErrors($form, jqXHR.responseJSON.errors);
            return;
        }
        if (jqXHR.status === 401 || jqXHR.status === 419) {
            window.alert(tablesT('cell.session_expired'));
            return;
        }
        const msg = jqXHR.responseJSON?.message || jqXHR.statusText || tablesT('row_actions.generic_error');
        window.alert(tablesT('row_actions.error_label', { message: msg }));
    }).always(function () {
        $submit.prop('disabled', false);
    });
});
