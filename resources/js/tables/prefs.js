import jQuery from 'jquery';

const $ = jQuery;

const PREFS_QUERY_KEYS = ['columns', 'density', 'per_page'];

function getBootstrap() {
    return window.bootstrap;
}

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

function findTriggerForKey(key) {
    return document.querySelector('[data-tables-prefs-trigger="' + key + '"]');
}

function findTemplateForKey(key) {
    return document.querySelector('template[data-tables-prefs-template="' + key + '"]');
}

function getOrCreatePopover(triggerEl) {
    const Bootstrap = getBootstrap();
    if (!Bootstrap || !Bootstrap.Popover) return null;

    let inst = Bootstrap.Popover.getInstance(triggerEl);
    if (inst) return inst;

    const key = triggerEl.getAttribute('data-tables-prefs-trigger');
    const tpl = findTemplateForKey(key);
    if (!tpl) return null;

    inst = new Bootstrap.Popover(triggerEl, {
        html: true,
        sanitize: false,
        trigger: 'manual',
        placement: 'bottom',
        container: 'body',
        customClass: 'ap-prefs-popover',
        content: () => {
            const fragment = tpl.content.cloneNode(true);
            const wrapper = document.createElement('div');
            wrapper.appendChild(fragment);
            return wrapper.innerHTML;
        },
    });

    triggerEl.addEventListener('shown.bs.popover', () => {
        const tip = inst.tip;
        if (!tip) return;
        const firstInput = tip.querySelector('input, select, button');
        if (firstInput) firstInput.focus();
    });

    return inst;
}

function hideAllPrefsPopovers() {
    document.querySelectorAll('[data-tables-prefs-trigger]').forEach((trigger) => {
        const Bootstrap = getBootstrap();
        if (!Bootstrap || !Bootstrap.Popover) return;
        const inst = Bootstrap.Popover.getInstance(trigger);
        if (inst) inst.hide();
    });
    document.querySelectorAll('.popover .ap-prefs-popover-form').forEach((form) => {
        const popover = form.closest('.popover');
        if (popover) popover.remove();
    });
}

function findFormPopover(formEl) {
    return formEl.closest('.popover');
}

function findTriggerForPopover(popoverEl) {
    if (!popoverEl) return null;
    const triggers = document.querySelectorAll('[data-tables-prefs-trigger]');
    for (let i = 0; i < triggers.length; i++) {
        const Bootstrap = getBootstrap();
        if (!Bootstrap || !Bootstrap.Popover) break;
        const inst = Bootstrap.Popover.getInstance(triggers[i]);
        if (inst && inst.tip === popoverEl) return triggers[i];
    }
    return null;
}

function showError($form, message) {
    const $err = $form.find('[data-tables-prefs-error]');
    $err.text(message).removeClass('d-none');
}

function clearError($form) {
    $form.find('[data-tables-prefs-error]').text('').addClass('d-none');
}

$(document).on('click', '[data-tables-prefs-trigger]', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const triggerEl = this;
    const inst = getOrCreatePopover(triggerEl);
    if (!inst) return;

    document.querySelectorAll('[data-tables-prefs-trigger]').forEach((other) => {
        if (other === triggerEl) return;
        const otherInst = getBootstrap()?.Popover?.getInstance(other);
        if (otherInst) otherInst.hide();
    });

    inst.toggle();
});

$(document).on('click', function (e) {
    const target = e.target;
    if (target.closest('[data-tables-prefs-trigger]')) return;
    if (target.closest('.popover .ap-prefs-popover-form')) return;

    document.querySelectorAll('[data-tables-prefs-trigger]').forEach((trigger) => {
        const Bootstrap = getBootstrap();
        if (!Bootstrap || !Bootstrap.Popover) return;
        const inst = Bootstrap.Popover.getInstance(trigger);
        if (inst) inst.hide();
    });
});

$(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    hideAllPrefsPopovers();
});

$(document).on('click', '[data-tables-prefs-cancel]', function (e) {
    e.preventDefault();
    const popoverEl = findFormPopover(this);
    const trigger = findTriggerForPopover(popoverEl);
    if (!trigger) return;
    const inst = getBootstrap()?.Popover?.getInstance(trigger);
    if (inst) inst.hide();
});

$(document).on('submit', 'form[data-tables-prefs-form]', function (e) {
    e.preventDefault();
    const $form = $(this);
    const popoverEl = findFormPopover(this);
    const trigger = findTriggerForPopover(popoverEl);
    if (!trigger) return;

    clearError($form);
    const $submit = $form.find('[data-tables-prefs-submit]');
    $submit.prop('disabled', true);

    const saveUrl = trigger.getAttribute('data-save-url') || '';

    $.ajax({
        url: saveUrl,
        type: 'POST',
        data: $form.serialize(),
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    }).done(function () {
        const inst = getBootstrap()?.Popover?.getInstance(trigger);
        if (inst) inst.hide();
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
                messages.push('Сессия истекла. Перезагрузите страницу.');
            } else {
                messages.push('Не удалось сохранить настройки. Попробуйте позже.');
            }
        }
        showError($form, messages.join(' '));
    }).always(function () {
        $submit.prop('disabled', false);
    });
});

$(document).on('click', '[data-tables-prefs-reset]', function (e) {
    e.preventDefault();
    const popoverEl = findFormPopover(this);
    const trigger = findTriggerForPopover(popoverEl);
    if (!trigger) return;

    if (!window.confirm('Сбросить настройки таблицы?')) return;

    const resetUrl = trigger.getAttribute('data-reset-url') || '';

    $.ajax({
        url: resetUrl,
        type: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    }).done(function () {
        const inst = getBootstrap()?.Popover?.getInstance(trigger);
        if (inst) inst.hide();
        const cleaned = buildCleanedUrl();
        dispatchNavigate(cleaned);
    }).fail(function (jqXHR) {
        const $form = $(popoverEl).find('form[data-tables-prefs-form]');
        const msg = jqXHR.status === 419
            ? 'Сессия истекла. Перезагрузите страницу.'
            : 'Не удалось сбросить настройки. Попробуйте позже.';
        if ($form.length > 0) {
            showError($form, msg);
        } else {
            window.alert(msg);
        }
    });
});

document.addEventListener('tables:rendered', function () {
    document.querySelectorAll('.popover').forEach((popover) => {
        if (popover.querySelector('[data-tables-prefs-popover-marker]')) {
            popover.remove();
        }
    });
});
