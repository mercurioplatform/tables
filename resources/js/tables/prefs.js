import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesConfirm } from './confirm.js';
import { tablesT } from './i18n.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

const PREFS_QUERY_KEYS = ['columns', 'density', 'per_page'];

function dispatchNavigate(url) {
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url } }));
}

function buildCleanedUrl() {
    const url = new URL(window.location.href);
    PREFS_QUERY_KEYS.forEach((key) => {
        url.searchParams.delete(key);
        url.searchParams.delete(key + '[]');
    });
    return url.toString();
}

function findTriggerForForm($form) {
    return $form.closest('.ap-prefs-popover').find(sel(ATTRS.PREFS_TRIGGER)).first();
}

function hidePopover($trigger) {
    const Bootstrap = window.bootstrap;
    if (!Bootstrap || !Bootstrap.Dropdown) return;
    const inst = Bootstrap.Dropdown.getOrCreateInstance($trigger.get(0));
    if (inst) inst.hide();
}

function showError($form, message) {
    const $err = $form.find(sel(ATTRS.PREFS_ERROR));
    $err.text(message).removeClass('d-none');
}

function clearError($form) {
    $form.find(sel(ATTRS.PREFS_ERROR)).text('').addClass('d-none');
}

function serializePrefsForm($form) {
    const params = new URLSearchParams();
    // Inside jQuery .each callback, 'this' is the raw <input> — .value is the short idiomatic read.
    $form.find('input[name="columns[]"]:checked').each(function () {
        params.append('columns[]', this.value);
    });
    const density = $form.find('input[name="density"]:checked').val();
    if (density) params.set('density', density);
    const perPage = $form.find('select[name="per_page"]').val();
    if (perPage) params.set('per_page', perPage);
    return params.toString();
}

$(document).on('click', sel(ATTRS.PREFS_FORM), function (e) {
    e.stopPropagation();
});

$(document).on('click', sel(ATTRS.PREFS_CANCEL), function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest(sel(ATTRS.PREFS_FORM));
    const $trigger = findTriggerForForm($form);
    if ($trigger.length === 0) return;
    hidePopover($trigger);
});

$(document).on('click', sel(ATTRS.PREFS_SUBMIT), function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest(sel(ATTRS.PREFS_FORM));
    const $trigger = findTriggerForForm($form);
    if ($trigger.length === 0) return;

    clearError($form);
    const $submit = $form.find(sel(ATTRS.PREFS_SUBMIT));
    $submit.prop('disabled', true);

    const saveUrl = $trigger.attr('data-save-url') || '';

    tablesAjax({
        url: saveUrl,
        type: 'POST',
        data: serializePrefsForm($form),
        partial: false,
        headers: { Accept: 'application/json' },
    }).done(function () {
        hidePopover($trigger);
        const cleaned = buildCleanedUrl();
        dispatchNavigate(cleaned);
    }).fail(function (jqXHR) {
        const data = jqXHR.responseJSON || {};
        const errors = data.errors || {};
        const messages = [];
        Object.keys(errors).forEach((field) => {
            const msg = Array.isArray(errors[field]) ? errors[field][0] : String(errors[field]);
            messages.push(msg);
        });
        if (messages.length === 0) {
            if (jqXHR.status === 419) {
                messages.push(tablesT('prefs.session_expired'));
            } else {
                messages.push(tablesT('prefs.save_failed'));
            }
        }
        showError($form, messages.join(' '));
    }).always(function () {
        $submit.prop('disabled', false);
    });
});

$(document).on('click', sel(ATTRS.PREFS_RESET), function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest(sel(ATTRS.PREFS_FORM));
    const $trigger = findTriggerForForm($form);
    if ($trigger.length === 0) return;

    tablesConfirm({
        title: tablesT('prefs.reset_confirm_title'),
        message: tablesT('prefs.reset_confirm_message'),
        confirmText: tablesT('prefs.reset_confirm_button'),
        cancelText: tablesT('prefs.cancel'),
        confirmVariant: 'danger',
        icon: 'bi-arrow-counterclockwise',
    }).then((ok) => {
        if (!ok) return;

        const resetUrl = $trigger.attr('data-reset-url') || '';

        tablesAjax({
            url: resetUrl,
            type: 'DELETE',
            partial: false,
            headers: { Accept: 'application/json' },
        }).done(function () {
            hidePopover($trigger);
            const cleaned = buildCleanedUrl();
            dispatchNavigate(cleaned);
        }).fail(function (jqXHR) {
            const msg = jqXHR.status === 419
                ? tablesT('prefs.session_expired')
                : tablesT('prefs.reset_failed');
            showError($form, msg);
        });
    });
});
