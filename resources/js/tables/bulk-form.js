import jQuery from 'jquery';
import { tablesT } from './i18n.js';

const $ = jQuery;

const OFFCANVAS_ID = 'tables-bulk-action-offcanvas';

function getOffcanvasEl() {
    // Raw HTMLElement required by bootstrap.Offcanvas.getOrCreateInstance() — keep native.
    return document.getElementById(OFFCANVAS_ID);
}

function getBootstrap() {
    return window.bootstrap;
}

function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
}

function navigateReload($form) {
    const $page = $form.closest('[data-tables-page]');
    if ($page.length === 0) return;
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent('tables:navigate', {
        detail: { url: window.location.href, push: false },
    }));
}

function readSelectedIds($scope) {
    const ids = [];
    $scope.find('[data-tables-row-checkbox]:checked').each(function () {
        const id = $(this).attr('data-tables-row-id');
        if (id) ids.push(String(id));
    });
    return ids;
}

function clearSelection($page) {
    $page.find('[data-tables-bulk-form] [data-tables-bulk-clear]').trigger('click');
}

function clearFieldErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.invalid-feedback[data-tables-bulk-action-error]').remove();
}

function renderFieldErrors($form, errors) {
    clearFieldErrors($form);
    if (!errors || typeof errors !== 'object') return;

    Object.keys(errors).forEach(function (name) {
        const messages = Array.isArray(errors[name]) ? errors[name] : [errors[name]];
        const $field = $form.find('[name="' + name + '"]').first();
        if ($field.length === 0) return;
        $field.addClass('is-invalid');
        const $feedback = $('<div class="invalid-feedback" data-tables-bulk-action-error></div>')
            .text(messages.join(' '));
        if ($field.next('.invalid-feedback').length > 0) {
            $field.next('.invalid-feedback').replaceWith($feedback);
        } else {
            $field.after($feedback);
        }
    });
}

$(document).on('click', '[data-tables-bulk-action-form-button]', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const formUrl = $btn.attr('data-form-url');
    const label = $btn.attr('data-action-label') || '';
    const reloadAfter = $btn.attr('data-reload-after') !== '0';

    const $page = $btn.closest('[data-tables-page]');
    const $scope = $page.find('[data-tables-bulk-scope]').first();
    const ids = readSelectedIds($scope);

    if (ids.length === 0) {
        window.alert(tablesT('bulk.empty_selection'));
        return;
    }

    const oc = getOffcanvasEl();
    if (!oc) {
        console.warn('[tables] bulk-action offcanvas not found on the page');
        return;
    }
    const bootstrap = getBootstrap();
    if (!bootstrap || !bootstrap.Offcanvas) {
        console.warn('[tables] bootstrap.Offcanvas is not available');
        return;
    }

    const $oc = $(oc);
    $oc.find('.offcanvas-title').text(label);
    const $body = $oc.find('[data-tables-bulk-action-body]');
    $body.attr('data-reload-after', reloadAfter ? '1' : '0');
    $body.addClass('is-loading').html(
        '<div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">' +
            tablesT('bulk.form.loading') +
            '</span></div></div>'
    );

    const instance = bootstrap.Offcanvas.getOrCreateInstance(oc);
    instance.show();

    $.ajax({
        url: formUrl,
        method: 'GET',
        data: { ids: ids.join(',') },
        headers: { 'X-Tables-Partial': '1' },
        dataType: 'html',
    }).done(function (html) {
        $body.removeClass('is-loading').html(html);
    }).fail(function (jqXHR) {
        const message = jqXHR.status === 401 || jqXHR.status === 419
            ? tablesT('bulk.session_expired')
            : tablesT('bulk.form.load_failed');
        $body.removeClass('is-loading').html(
            '<div class="alert alert-danger m-0">' + message + '</div>'
        );
    });
});

$(document).on('submit', 'form[data-tables-bulk-action-submit]', function (e) {
    e.preventDefault();
    const $form = $(this);
    const url = $form.attr('action');
    if (!url) return;

    const $page = $form.closest('[data-tables-page]').length
        ? $form.closest('[data-tables-page]')
        : $(document).find('[data-tables-page]').first();
    const $scope = $page.find('[data-tables-bulk-scope]').first();
    const ids = readSelectedIds($scope);

    if (ids.length === 0) {
        window.alert(tablesT('bulk.form.empty_selection'));
        return;
    }

    $form.find('input[name="ids"]').val(ids.join(','));

    const $submit = $form.find('button[type="submit"]');
    $submit.prop('disabled', true);
    clearFieldErrors($form);

    const reloadAfter = $form.closest('[data-tables-bulk-action-body]').attr('data-reload-after') !== '0';

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
