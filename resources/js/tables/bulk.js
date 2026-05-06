import jQuery from 'jquery';
import { tablesConfirm } from './confirm.js';

const $ = jQuery;

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
    return $('[data-tables-bulk-scope]').filter(function () {
        return $(this).attr('data-tables-bulk-scope') === resourceKey;
    });
}

function findForm(resourceKey) {
    return $('[data-tables-bulk-form]').filter(function () {
        return $(this).attr('data-tables-bulk-form') === resourceKey;
    });
}

function updateBar(resourceKey) {
    const $form = findForm(resourceKey);
    if ($form.length === 0) return;
    const set = getSet(resourceKey);
    $form.find('[data-tables-bulk-count]').text(set.size);
    if (set.size > 0) {
        $form.removeAttr('hidden');
    } else {
        $form.attr('hidden', 'hidden');
    }
}

function updateHeader(resourceKey) {
    const $scope = findScope(resourceKey);
    if ($scope.length === 0) return;
    const $rows = $scope.find('[data-tables-row-checkbox]');
    const $all = $scope.find('[data-tables-select-all]');
    const total = $rows.length;
    const checked = $rows.filter(':checked').length;
    const allEl = $all.get(0);
    if (!allEl) return;
    allEl.checked = total > 0 && checked === total;
    allEl.indeterminate = checked > 0 && checked < total;
}

$(document).on('change', '[data-tables-bulk-scope] [data-tables-row-checkbox]', function () {
    const $cb = $(this);
    const $scope = $cb.closest('[data-tables-bulk-scope]');
    const resourceKey = $scope.attr('data-tables-bulk-scope');
    if (!resourceKey) return;
    const id = $cb.attr('data-tables-row-id');
    if (!id) return;
    const set = getSet(resourceKey);
    const $row = $cb.closest('[data-tables-row]');
    if (this.checked) {
        set.add(String(id));
        $row.addClass('is-selected');
    } else {
        set.delete(String(id));
        $row.removeClass('is-selected');
    }
    updateBar(resourceKey);
    updateHeader(resourceKey);
});

$(document).on('change', '[data-tables-bulk-scope] [data-tables-select-all]', function () {
    const $all = $(this);
    const checked = this.checked;
    const $scope = $all.closest('[data-tables-bulk-scope]');
    const resourceKey = $scope.attr('data-tables-bulk-scope');
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    $scope.find('[data-tables-row-checkbox]').each(function () {
        const id = $(this).attr('data-tables-row-id');
        if (!id) return;
        this.checked = checked;
        const $row = $(this).closest('[data-tables-row]');
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

$(document).on('click', '[data-tables-bulk-form] [data-tables-bulk-clear]', function (e) {
    e.preventDefault();
    const $form = $(this).closest('[data-tables-bulk-form]');
    const resourceKey = $form.attr('data-tables-bulk-form');
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    set.clear();
    const $scope = findScope(resourceKey);
    $scope.find('[data-tables-row-checkbox]').each(function () {
        this.checked = false;
        $(this).closest('[data-tables-row]').removeClass('is-selected');
    });
    const $all = $scope.find('[data-tables-select-all]');
    const allEl = $all.get(0);
    if (allEl) {
        allEl.checked = false;
        allEl.indeterminate = false;
    }
    updateBar(resourceKey);
});

$(document).on('click', '[data-tables-bulk-form] [data-tables-bulk-action]', function () {
    const $btn = $(this);
    const $form = $btn.closest('[data-tables-bulk-form]');
    $form.data('pendingAction', $btn.attr('data-tables-bulk-action') || '');
    $form.data('pendingConfirm', $btn.attr('data-tables-bulk-confirm') || '');
});

$(document).on('submit', '[data-tables-bulk-form]', async function (e) {
    const $form = $(this);
    const resourceKey = $form.attr('data-tables-bulk-form');
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    if (set.size === 0) {
        e.preventDefault();
        return;
    }
    const confirmText = $form.data('pendingConfirm') || '';
    if (confirmText) {
        e.preventDefault();
        const ok = await tablesConfirm({
            title: 'Подтверждение',
            message: confirmText,
            confirmText: 'Подтвердить',
            confirmVariant: 'danger',
            icon: 'bi-exclamation-circle',
        });
        if (ok) {
            $form.data('pendingConfirm', '');
            $form.find('[data-tables-bulk-action-input]').val($form.data('pendingAction') || '');
            $form.find('[data-tables-bulk-ids-input]').val([...set].join(','));
            HTMLFormElement.prototype.submit.call($form.get(0));
        }
        return;
    }
    $form.find('[data-tables-bulk-action-input]').val($form.data('pendingAction') || '');
    $form.find('[data-tables-bulk-ids-input]').val([...set].join(','));
});

$(document).on('tables:rendered', '[data-tables-root]', function () {
    const $root = $(this);
    const $scope = $root.find('[data-tables-bulk-scope]').first();
    if ($scope.length === 0) return;
    const resourceKey = $scope.attr('data-tables-bulk-scope');
    if (!resourceKey) return;
    const set = getSet(resourceKey);
    if (set.size === 0) {
        updateHeader(resourceKey);
        return;
    }
    $scope.find('[data-tables-row-checkbox]').each(function () {
        const id = $(this).attr('data-tables-row-id');
        if (id && set.has(String(id))) {
            this.checked = true;
            $(this).closest('[data-tables-row]').addClass('is-selected');
        }
    });
    updateHeader(resourceKey);
});
