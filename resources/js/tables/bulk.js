import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesConfirm } from './confirm.js';
import { tablesT } from './i18n.js';
import { logger } from './logger.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;
const log = logger.scope('bulk_progress');

const selections = new Map();

function getSet(resourceKey) {
    let s = selections.get(resourceKey);
    if (!s) {
        s = new Set();
        selections.set(resourceKey, s);
    }
    return s;
}

function findScope(resourceKey) {
    return $(sel(ATTRS.BULK_SCOPE, resourceKey));
}

function findForm(resourceKey) {
    return $(sel(ATTRS.BULK_FORM, resourceKey));
}

function updateBar(resourceKey) {
    const $form = findForm(resourceKey);
    if ($form.length === 0) return;
    const set = getSet(resourceKey);
    $form.find(sel(ATTRS.BULK_COUNT)).text(set.size);
    if (set.size > 0) {
        $form.removeAttr('hidden');
    } else {
        $form.attr('hidden', 'hidden');
    }
}

function updateHeader(resourceKey) {
    const $scope = findScope(resourceKey);
    if ($scope.length === 0) return;
    const $rows = $scope.find(sel(ATTRS.ROW_CHECKBOX));
    const $all = $scope.find(sel(ATTRS.SELECT_ALL));
    const total = $rows.length;
    const checked = $rows.filter(':checked').length;
    if ($all.length === 0) return;
    $all.prop('checked', total > 0 && checked === total);
    $all.prop('indeterminate', checked > 0 && checked < total);
}

$(document).on('change', sel(ATTRS.BULK_SCOPE) + ' ' + sel(ATTRS.ROW_CHECKBOX), function () {
    const $cb = $(this);
    const $scope = $cb.closest(sel(ATTRS.BULK_SCOPE));
    const resourceKey = $scope.attr(ATTRS.BULK_SCOPE);
    if (!resourceKey) return;
    const id = $cb.attr(ATTRS.ROW_ID);
    if (!id) return;
    const set = getSet(resourceKey);
    const $row = $cb.closest(sel(ATTRS.ROW));
    if ($(this).prop('checked')) {
        set.add(String(id));
        $row.addClass('is-selected');
    } else {
        set.delete(String(id));
        $row.removeClass('is-selected');
    }
    updateBar(resourceKey);
    updateHeader(resourceKey);
});

$(document).on('change', sel(ATTRS.BULK_SCOPE) + ' ' + sel(ATTRS.SELECT_ALL), function () {
    const $all = $(this);
    const checked = $(this).prop('checked');
    const $scope = $all.closest(sel(ATTRS.BULK_SCOPE));
    const resourceKey = $scope.attr(ATTRS.BULK_SCOPE);
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    $scope.find(sel(ATTRS.ROW_CHECKBOX)).each(function () {
        const id = $(this).attr(ATTRS.ROW_ID);
        if (!id) return;
        $(this).prop('checked', checked);
        const $row = $(this).closest(sel(ATTRS.ROW));
        if (checked) {
            set.add(String(id));
            $row.addClass('is-selected');
        } else {
            set.delete(String(id));
            $row.removeClass('is-selected');
        }
    });
    updateBar(resourceKey);
    updateHeader(resourceKey);
});

$(document).on('click', sel(ATTRS.BULK_FORM) + ' ' + sel(ATTRS.BULK_CLEAR), function (e) {
    e.preventDefault();
    const $form = $(this).closest(sel(ATTRS.BULK_FORM));
    const resourceKey = $form.attr(ATTRS.BULK_FORM);
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    set.clear();
    const $scope = findScope(resourceKey);
    $scope.find(sel(ATTRS.ROW_CHECKBOX)).each(function () {
        $(this).prop('checked', false);
        $(this).closest(sel(ATTRS.ROW)).removeClass('is-selected');
    });
    const $all = $scope.find(sel(ATTRS.SELECT_ALL));
    if ($all.length > 0) {
        $all.prop({ checked: false, indeterminate: false });
    }
    updateBar(resourceKey);
});

$(document).on('click', sel(ATTRS.BULK_FORM) + ' ' + sel(ATTRS.BULK_ACTION), function () {
    const $btn = $(this);
    const $form = $btn.closest(sel(ATTRS.BULK_FORM));
    $form.data('pendingAction', $btn.attr(ATTRS.BULK_ACTION) || '');
    $form.data('pendingConfirm', $btn.attr(ATTRS.BULK_CONFIRM) || '');
    $form.data('pendingQueued', $btn.attr(ATTRS.BULK_QUEUED) === '1' ? '1' : '');
    $form.data('pendingLabel', $btn.attr('data-action-label') || $btn.text().trim());
});

function fillFormHidden($form, set) {
    $form.find(sel(ATTRS.BULK_ACTION_INPUT)).val($form.data('pendingAction') || '');
    $form.find(sel(ATTRS.BULK_IDS_INPUT)).val([...set].join(','));
}

export function submitBulkAjax($form, resourceKey) {
    const url = $form.attr('action');
    const formData = $form.serialize();
    const actionLabel = $form.data('pendingLabel') || tablesT('bulk.queued_default_label');

    tablesAjax({
        url,
        method: 'POST',
        data: formData,
        csrf: false,
        dataType: 'json',
    })
        .done((data) => {
            if (data && data.status === 'queued' && data.progress_id) {
                if (window.TablesProgress && typeof window.TablesProgress.enqueue === 'function') {
                    window.TablesProgress.enqueue({
                        progressId: data.progress_id,
                        progressUrl: data.progress_url,
                        indexUrl: data.index_url,
                        actionLabel: data.action_label || actionLabel,
                        total: data.total | 0,
                    });
                }
                const set = getSet(resourceKey);
                set.clear();
                const $scope = findScope(resourceKey);
                $scope.find(sel(ATTRS.ROW_CHECKBOX)).each(function () {
                    $(this).prop('checked', false);
                    $(this).closest(sel(ATTRS.ROW)).removeClass('is-selected');
                });
                const $all = $scope.find(sel(ATTRS.SELECT_ALL));
                if ($all.length > 0) {
                    $all.prop({ checked: false, indeterminate: false });
                }
                updateBar(resourceKey);
                updateHeader(resourceKey);
                return;
            }
            window.location.reload();
        })
        .fail((xhr) => {
            const message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : tablesT('bulk.queued_dispatch_failed', { status: xhr ? xhr.status : '?' });
            log.error(xhr ? xhr.status : null, message);
            if (window.TablesProgress && typeof window.TablesProgress.errorToast === 'function') {
                window.TablesProgress.errorToast(message);
            } else {
                window.alert(message);
            }
        });
}

$(document).on('submit', sel(ATTRS.BULK_FORM), async function (e) {
    const $form = $(this);
    const resourceKey = $form.attr(ATTRS.BULK_FORM);
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    if (set.size === 0) {
        e.preventDefault();
        return;
    }

    const isQueued = $form.data('pendingQueued') === '1';
    const confirmText = $form.data('pendingConfirm') || '';

    if (confirmText) {
        e.preventDefault();
        const ok = await tablesConfirm({
            title: tablesT('confirm.title_default'),
            message: confirmText,
            confirmText: tablesT('confirm.confirm_default'),
            confirmVariant: 'danger',
            icon: 'bi-exclamation-circle',
        });
        if (!ok) return;
        $form.data('pendingConfirm', '');
        fillFormHidden($form, set);
        if (isQueued) {
            submitBulkAjax($form, resourceKey);
        } else {
            HTMLFormElement.prototype.submit.call($form.get(0));
        }
        return;
    }

    if (isQueued) {
        e.preventDefault();
        fillFormHidden($form, set);
        submitBulkAjax($form, resourceKey);
        return;
    }

    fillFormHidden($form, set);
});

$(document).on(EVENTS.RENDERED, sel(ATTRS.ROOT), function () {
    const $root = $(this);
    const $scope = $root.find(sel(ATTRS.BULK_SCOPE)).first();
    if ($scope.length === 0) return;
    const resourceKey = $scope.attr(ATTRS.BULK_SCOPE);
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    if (set.size === 0) {
        updateHeader(resourceKey);
        return;
    }
    $scope.find(sel(ATTRS.ROW_CHECKBOX)).each(function () {
        const id = $(this).attr(ATTRS.ROW_ID);
        if (id && set.has(String(id))) {
            $(this).prop('checked', true);
            $(this).closest(sel(ATTRS.ROW)).addClass('is-selected');
        }
    });
    updateHeader(resourceKey);
});
