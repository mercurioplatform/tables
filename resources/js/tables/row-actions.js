import jQuery from 'jquery';
import { tablesConfirm } from './confirm.js';

const $ = jQuery;

const OFFCANVAS_ID = 'tables-row-action-offcanvas';

function getOffcanvasEl() {
    return document.getElementById(OFFCANVAS_ID);
}

function getBootstrap() {
    return window.bootstrap;
}

function navigateReload($form) {
    const $page = $form.closest('[data-tables-page]');
    if ($page.length === 0) return;
    document.dispatchEvent(new CustomEvent('tables:navigate', {
        detail: { url: window.location.href, push: false },
    }));
}

function clearFieldErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.invalid-feedback[data-tables-row-action-error]').remove();
}

function renderFieldErrors($form, errors) {
    clearFieldErrors($form);
    if (!errors || typeof errors !== 'object') return;

    Object.keys(errors).forEach(function (name) {
        const messages = Array.isArray(errors[name]) ? errors[name] : [errors[name]];
        const $field = $form.find('[name="' + name + '"]').first();
        if ($field.length === 0) return;
        $field.addClass('is-invalid');
        const $feedback = $('<div class="invalid-feedback" data-tables-row-action-error></div>')
            .text(messages.join(' '));
        if ($field.next('.invalid-feedback').length > 0) {
            $field.next('.invalid-feedback').replaceWith($feedback);
        } else {
            $field.after($feedback);
        }
    });
}

function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
}

$(document).on('click', '[data-tables-row-action-confirm]:not([data-tables-row-preview-url])', async function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    const $btn = $(this);
    const text = $btn.attr('data-confirm-text') || 'Подтвердить?';
    const ok = await tablesConfirm({
        title: 'Подтверждение',
        message: text,
        confirmText: 'Подтвердить',
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

$(document).on('click', '[data-tables-row-action-form-button]', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const formUrl = $btn.attr('data-form-url');
    const submitUrl = $btn.attr('data-submit-url');
    const label = $btn.attr('data-action-label') || '';
    const reloadAfter = $btn.attr('data-reload-after') !== '0';

    const oc = getOffcanvasEl();
    if (!oc) {
        console.warn('[tables] row-action offcanvas not found on the page');
        return;
    }
    const bootstrap = getBootstrap();
    if (!bootstrap || !bootstrap.Offcanvas) {
        console.warn('[tables] bootstrap.Offcanvas is not available');
        return;
    }

    const $oc = $(oc);
    $oc.find('.offcanvas-title').text(label);
    const $body = $oc.find('[data-tables-row-action-body]');
    $body.attr('data-submit-url', submitUrl);
    $body.attr('data-reload-after', reloadAfter ? '1' : '0');
    $body.addClass('is-loading').html(
        '<div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Загрузка...</span></div></div>'
    );

    const instance = bootstrap.Offcanvas.getOrCreateInstance(oc);
    instance.show();

    $.ajax({
        url: formUrl,
        method: 'GET',
        headers: { 'X-Tables-Partial': '1' },
        dataType: 'html',
    }).done(function (html) {
        $body.removeClass('is-loading').html(html);
    }).fail(function (jqXHR) {
        const message = jqXHR.status === 401 || jqXHR.status === 419
            ? 'Сессия истекла. Перезагрузите страницу.'
            : 'Не удалось загрузить форму';
        $body.removeClass('is-loading').html(
            '<div class="alert alert-danger m-0">' + message + '</div>'
        );
    });
});

$(document).on('submit', 'form[data-tables-row-action-submit]', function (e) {
    e.preventDefault();
    const $form = $(this);
    const url = $form.attr('action');
    if (!url) return;

    const $submit = $form.find('button[type="submit"]');
    $submit.prop('disabled', true);
    clearFieldErrors($form);

    const reloadAfter = $form.closest('[data-tables-row-action-body]').attr('data-reload-after') !== '0';

    $.ajax({
        url: url,
        method: 'POST',
        data: $form.serialize(),
        headers: {
            'X-Tables-Partial': '1',
            'X-CSRF-TOKEN': getCsrfToken(),
            'Accept': 'application/json',
        },
        dataType: 'json',
    }).done(function (data) {
        const oc = getOffcanvasEl();
        if (oc) {
            const bootstrap = getBootstrap();
            const instance = bootstrap?.Offcanvas?.getInstance(oc);
            instance?.hide();
        }
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
            window.alert('Сессия истекла. Перезагрузите страницу.');
            return;
        }
        const msg = jqXHR.responseJSON?.message || jqXHR.statusText || 'Ошибка';
        window.alert('Ошибка: ' + msg);
    }).always(function () {
        $submit.prop('disabled', false);
    });
});
