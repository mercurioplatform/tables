import $ from 'jquery';

const URL_ATTR = 'data-tables-action-log-url';
const TRIGGER_ATTR = 'data-tables-action-log-trigger';

function loadInto($body, url) {
    $body.addClass('is-loading');
    $.ajax({
        url,
        method: 'GET',
        headers: { 'X-Tables-Partial': '1' },
    })
        .done((html) => {
            $body.html(html);
        })
        .fail((xhr) => {
            $body.html(
                '<div class="alert alert-danger m-3 small">Не удалось загрузить историю (HTTP ' +
                    xhr.status +
                    ').</div>',
            );
        })
        .always(() => {
            $body.removeClass('is-loading');
        });
}

$(document).on('show.bs.offcanvas', '[data-tables-action-log]', function () {
    const $offcanvas = $(this);
    const id = $offcanvas.attr('id');
    const $trigger = $('[' + TRIGGER_ATTR + '="' + id + '"]').first();
    const url = $trigger.attr(URL_ATTR);
    if (!url) {
        return;
    }
    const $body = $offcanvas.find('[data-tables-action-log-body]');
    loadInto($body, url);
});

$(document).on('click', '[data-tables-action-log-body] a[href]', function (e) {
    const href = $(this).attr('href');
    if (!href || href === '#' || href.startsWith('javascript:')) {
        return;
    }
    e.preventDefault();
    const $body = $(this).closest('[data-tables-action-log-body]');
    loadInto($body, href);
});

$(document).on('submit', '[data-tables-action-log-undo]', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $offcanvas = $form.closest('[data-tables-action-log]');
    const $body = $offcanvas.find('[data-tables-action-log-body]');
    const url = $form.attr('action');
    const csrf = $form.find('input[name="_token"]').val();
    const $btn = $form.find('button[type="submit"]');

    $btn.prop('disabled', true);

    $.ajax({
        url,
        method: 'POST',
        headers: {
            'X-Tables-Partial': '1',
            'X-CSRF-TOKEN': csrf,
            Accept: 'application/json',
        },
    })
        .done((resp) => {
            const trigger = $offcanvas.attr('id');
            const $trig = $('[' + TRIGGER_ATTR + '="' + trigger + '"]').first();
            const reloadUrl = $trig.attr(URL_ATTR);
            if (reloadUrl) {
                loadInto($body, reloadUrl);
            }
            const msg = (resp && resp.message) || 'Откат выполнен.';
            $(document).trigger('tables:flash', [{ status: msg }]);
        })
        .fail((xhr) => {
            const msg =
                (xhr.responseJSON && xhr.responseJSON.message) ||
                'Не удалось откатить (HTTP ' + xhr.status + ').';
            $body.prepend(
                '<div class="alert alert-danger small m-2 alert-dismissible fade show" role="alert">' +
                    msg +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>' +
                    '</div>',
            );
            $btn.prop('disabled', false);
        });
});
