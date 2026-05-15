import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesT } from './i18n.js';
import { cloneSharedTemplate } from './shared-templates.js';
import { showOffcanvas, hideOffcanvas } from './offcanvas.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

const OFFCANVAS_ID = 'tables-bulk-action-offcanvas';

function navigateReload($form) {
    const $page = $form.closest(sel(ATTRS.PAGE));
    if ($page.length === 0) return;
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, {
        detail: { url: window.location.href, push: false },
    }));
}

function readSelectedIds($scope) {
    const ids = [];
    $scope.find(sel(ATTRS.ROW_CHECKBOX) + ':checked').each(function () {
        const id = $(this).attr(ATTRS.ROW_ID);
        if (id) ids.push(String(id));
    });
    return ids;
}

function clearSelection($page) {
    $page.find(sel(ATTRS.BULK_FORM) + ' ' + sel(ATTRS.BULK_CLEAR)).trigger('click');
}

function clearFieldErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.invalid-feedback' + sel(ATTRS.BULK_ACTION_ERROR)).remove();
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
        $feedback.attr(ATTRS.BULK_ACTION_ERROR, '').text(messages.join(' '));
        if ($field.next('.invalid-feedback').length > 0) {
            $field.next('.invalid-feedback').replaceWith($feedback);
        } else {
            $field.after($feedback);
        }
    });
}

$(document).on('click', sel(ATTRS.BULK_ACTION_FORM_BUTTON), function (e) {
    e.preventDefault();
    const $btn = $(this);
    const formUrl = $btn.attr('data-form-url');
    const label = $btn.attr('data-action-label') || '';
    const reloadAfter = $btn.attr('data-reload-after') !== '0';

    const $page = $btn.closest(sel(ATTRS.PAGE));
    const $scope = $page.find(sel(ATTRS.BULK_SCOPE)).first();
    const ids = readSelectedIds($scope);

    if (ids.length === 0) {
        window.alert(tablesT('bulk.empty_selection'));
        return;
    }

    const oc = document.getElementById(OFFCANVAS_ID);
    if (!oc) return;

    const $oc = $(oc);
    $oc.find('.offcanvas-title').text(label);
    const $body = $oc.find(sel(ATTRS.BULK_ACTION_BODY));
    $body.attr('data-reload-after', reloadAfter ? '1' : '0');
    $body.addClass('is-loading').empty();
    const $spinner = cloneSharedTemplate('loading-spinner', $btn);
    if ($spinner) {
        $spinner.find(sel(ATTRS.LOADING_TEXT)).text(tablesT('bulk.form.loading'));
        $body.append($spinner);
    }

    if (!showOffcanvas(oc)) return;

    tablesAjax({
        url: formUrl,
        method: 'GET',
        data: { ids: ids.join(',') },
        dataType: 'html',
    }).done(function (html) {
        $body.removeClass('is-loading').html(html);
    }).fail(function (jqXHR) {
        const message = jqXHR.status === 401 || jqXHR.status === 419
            ? tablesT('bulk.session_expired')
            : tablesT('bulk.form.load_failed');
        $body.removeClass('is-loading').empty();
        const $alert = cloneSharedTemplate('alert-danger', $btn);
        if ($alert) {
            $alert.text(message);
            $body.append($alert);
        }
    });
});

$(document).on('submit', 'form' + sel(ATTRS.BULK_ACTION_SUBMIT), function (e) {
    e.preventDefault();
    const $form = $(this);
    const url = $form.attr('action');
    if (!url) return;

    const $page = $form.closest(sel(ATTRS.PAGE)).length
        ? $form.closest(sel(ATTRS.PAGE))
        : $(document).find(sel(ATTRS.PAGE)).first();
    const $scope = $page.find(sel(ATTRS.BULK_SCOPE)).first();
    const ids = readSelectedIds($scope);

    if (ids.length === 0) {
        window.alert(tablesT('bulk.form.empty_selection'));
        return;
    }

    $form.find('input[name="ids"]').val(ids.join(','));

    const $submit = $form.find('button[type="submit"]');
    $submit.prop('disabled', true);
    clearFieldErrors($form);

    const reloadAfter = $form.closest(sel(ATTRS.BULK_ACTION_BODY)).attr('data-reload-after') !== '0';

    tablesAjax({
        url: url,
        method: 'POST',
        data: $form.serialize(),
        headers: { Accept: 'application/json' },
        dataType: 'json',
    }).done(function (data) {
        hideOffcanvas(OFFCANVAS_ID);
        clearSelection($page);
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
            window.alert(tablesT('bulk.session_expired'));
            return;
        }
        const msg = jqXHR.responseJSON?.message || jqXHR.statusText || tablesT('bulk.form.generic_error');
        window.alert(tablesT('bulk.form.error_label', { message: msg }));
    }).always(function () {
        $submit.prop('disabled', false);
    });
});
