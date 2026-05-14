import jQuery from 'jquery';
import { tablesConfirm } from './confirm.js';
import { tablesT } from './i18n.js';

const $ = jQuery;

const PREFS_QUERY_KEYS = ['columns', 'density', 'per_page'];

function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
}

function dispatchNavigate(url) {
    document.dispatchEvent(new CustomEvent('tables:navigate', { detail: { url } }));
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
    return $form.closest('.ap-prefs-popover').find('[data-tables-prefs-trigger]').first();
}

function hidePopover($trigger) {
    const Bootstrap = window.bootstrap;
    if (!Bootstrap || !Bootstrap.Dropdown) return;
    const inst = Bootstrap.Dropdown.getOrCreateInstance($trigger.get(0));
    if (inst) inst.hide();
}

function showError($form, message) {
    const $err = $form.find('[data-tables-prefs-error]');
    $err.text(message).removeClass('d-none');
}

function clearError($form) {
    $form.find('[data-tables-prefs-error]').text('').addClass('d-none');
}

function serializePrefsForm($form) {
    const params = new URLSearchParams();
    $form.find('input[name="columns[]"]:checked').each(function () {
        params.append('columns[]', this.value);
    });
    const density = $form.find('input[name="density"]:checked').val();
    if (density) params.set('density', density);
    const perPage = $form.find('select[name="per_page"]').val();
    if (perPage) params.set('per_page', perPage);
    return params.toString();
}

$(document).on('click', '[data-tables-prefs-form]', function (e) {
    e.stopPropagation();
});

$(document).on('click', '[data-tables-prefs-cancel]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest('[data-tables-prefs-form]');
    const $trigger = findTriggerForForm($form);
    if ($trigger.length === 0) return;
    hidePopover($trigger);
});

$(document).on('click', '[data-tables-prefs-submit]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest('[data-tables-prefs-form]');
    const $trigger = findTriggerForForm($form);
    if ($trigger.length === 0) return;

    clearError($form);
    const $submit = $form.find('[data-tables-prefs-submit]');
    $submit.prop('disabled', true);

    const saveUrl = $trigger.attr('data-save-url') || '';

    $.ajax({
        url: saveUrl,
        type: 'POST',
        data: serializePrefsForm($form),
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
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

$(document).on('click', '[data-tables-prefs-reset]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest('[data-tables-prefs-form]');
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

        $.ajax({
            url: resetUrl,
            type: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
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
