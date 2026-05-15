import $ from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesT } from './i18n.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

function loadInto($body, url) {
    $body.addClass('is-loading');
    tablesAjax({
        url,
        method: 'GET',
    })
        .done((html) => {
            $body.html(html);
        })
        .fail((xhr) => {
            $body.html(
                '<div class="alert alert-danger m-3 small">' +
                    tablesT('action_log.load_failed', { status: xhr.status }) +
                    '</div>',
            );
        })
        .always(() => {
            $body.removeClass('is-loading');
        });
}

$(document).on('show.bs.offcanvas', sel(ATTRS.ACTION_LOG), function () {
    const $offcanvas = $(this);
    const id = $offcanvas.attr('id');
    const $trigger = $(sel(ATTRS.ACTION_LOG_TRIGGER, id)).first();
    const url = $trigger.attr(ATTRS.ACTION_LOG_URL);
    if (!url) {
        return;
    }
    const $body = $offcanvas.find(sel(ATTRS.ACTION_LOG_BODY));
    loadInto($body, url);
});

$(document).on('click', sel(ATTRS.ACTION_LOG_BODY) + ' a[href]', function (e) {
    const href = $(this).attr('href');
    if (!href || href === '#' || href.startsWith('javascript:')) {
        return;
    }
    e.preventDefault();
    const $body = $(this).closest(sel(ATTRS.ACTION_LOG_BODY));
    loadInto($body, href);
});

$(document).on('submit', sel(ATTRS.ACTION_LOG_UNDO), function (e) {
    e.preventDefault();
    const $form = $(this);
    const $offcanvas = $form.closest(sel(ATTRS.ACTION_LOG));
    const $body = $offcanvas.find(sel(ATTRS.ACTION_LOG_BODY));
    const url = $form.attr('action');
    const csrf = $form.find('input[name="_token"]').val();
    const $btn = $form.find('button[type="submit"]');

    $btn.prop('disabled', true);

    tablesAjax({
        url,
        method: 'POST',
        csrf: csrf,
        headers: { Accept: 'application/json' },
    })
        .done((resp) => {
            const trigger = $offcanvas.attr('id');
            const $trig = $(sel(ATTRS.ACTION_LOG_TRIGGER, trigger)).first();
            const reloadUrl = $trig.attr(ATTRS.ACTION_LOG_URL);
            if (reloadUrl) {
                loadInto($body, reloadUrl);
            }
            const msg = (resp && resp.message) || tablesT('action_log.undo_default_success');
            $(document).trigger(EVENTS.FLASH, [{ status: msg }]);
        })
        .fail((xhr) => {
            const msg =
                (xhr.responseJSON && xhr.responseJSON.message) ||
                tablesT('action_log.undo_failed', { status: xhr.status });
            $body.prepend(
                '<div class="alert alert-danger small m-2 alert-dismissible fade show" role="alert">' +
                    msg +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' +
                    tablesT('shell.close') +
                    '"></button>' +
                    '</div>',
            );
            $btn.prop('disabled', false);
        });
});
