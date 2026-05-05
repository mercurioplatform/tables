import jQuery from 'jquery';

const $ = jQuery;

const inflight = new WeakMap();

function isModifiedClick(e) {
    return e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0;
}

function syncSavedViews($page, url) {
    const params = new URL(url, window.location.origin).searchParams;
    const view = params.get('view');
    const $links = $page.find('a[data-tables-saved-view][data-tables-saved-view-key]');
    if ($links.length === 0) return;

    $links.removeClass('is-active');

    let activated = false;
    if (view !== null && view !== '') {
        const $match = $links.filter(function () {
            return $(this).attr('data-tables-saved-view-key') === view;
        });
        if ($match.length > 0) {
            $match.first().addClass('is-active');
            activated = true;
        }
    }
    if (!activated) {
        $links.first().addClass('is-active');
    }
}

function requestPartial(url, $page, options) {
    const opts = options || {};
    const push = opts.push !== false;

    const pageEl = $page.get(0);
    if (!pageEl) return;

    const $root = $page.find('[data-tables-root]').first();
    if ($root.length === 0) return;

    const prev = inflight.get(pageEl);
    if (prev && prev.readyState !== 4) {
        prev.abort();
    }

    $root.addClass('is-loading');

    const xhr = $.ajax({
        url: url,
        method: 'GET',
        headers: { 'X-Tables-Partial': '1' },
        dataType: 'html',
    });

    inflight.set(pageEl, xhr);

    xhr.done(function (html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newRoot = doc.querySelector('[data-tables-root]');
        if (!newRoot) {
            console.error('[tables] response has no [data-tables-root]');
            return;
        }
        const oldRoot = $root.get(0);
        oldRoot.replaceWith(newRoot);

        const newFilterBar = doc.querySelector('[data-tables-filter-bar]');
        if (newFilterBar) {
            const $oldFilterBar = $page.find('[data-tables-filter-bar]').first();
            if ($oldFilterBar.length > 0) {
                $oldFilterBar.get(0).replaceWith(newFilterBar);
            }
        }

        syncSavedViews($page, url);
        $(newRoot).trigger('tables:rendered');
        document.dispatchEvent(new CustomEvent('tables:rendered', { detail: { url } }));
        if (push) {
            window.history.pushState(
                { tablesPage: $page.attr('data-tables-page'), url: url },
                '',
                url,
            );
        }
    });

    xhr.fail(function (jqXHR) {
        if (jqXHR.statusText === 'abort') return;
        console.error('[tables] ajax failed', jqXHR.status, jqXHR.statusText);
    });

    xhr.always(function () {
        $page.find('[data-tables-root]').first().removeClass('is-loading');
        if (inflight.get(pageEl) === xhr) {
            inflight.delete(pageEl);
        }
    });
}

$(document).on('submit', 'form[data-tables-search-form]', function (e) {
    const $form = $(this);
    const $page = $form.closest('[data-tables-page]');
    if ($page.length === 0) return;
    e.preventDefault();
    const query = $form.serialize();
    const action = $form.attr('action') || window.location.pathname;
    const url = action + (query ? '?' + query : '');
    requestPartial(url, $page);
});

$(document).on('click', 'a[data-tables-saved-view]', function (e) {
    const $a = $(this);
    const $page = $a.closest('[data-tables-page]');
    if ($page.length === 0) return;
    if (isModifiedClick(e)) return;
    const href = $a.attr('href');
    if (!href) return;
    e.preventDefault();
    requestPartial(href, $page);
});

$(document).on('click', '[data-tables-root] thead a[href]', function (e) {
    const $a = $(this);
    const $page = $a.closest('[data-tables-page]');
    if ($page.length === 0) return;
    if (isModifiedClick(e)) return;
    e.preventDefault();
    requestPartial($a.attr('href'), $page);
});

$(document).on('click', '[data-tables-root] .pagination a[href]', function (e) {
    const $a = $(this);
    const $page = $a.closest('[data-tables-page]');
    if ($page.length === 0) return;
    if (isModifiedClick(e)) return;
    e.preventDefault();
    requestPartial($a.attr('href'), $page);
});

document.addEventListener('tables:navigate', function (e) {
    const url = e?.detail?.url;
    if (!url) return;
    const $page = $('[data-tables-page]').first();
    if ($page.length === 0) return;
    requestPartial(url, $page);
});

window.addEventListener('popstate', function (e) {
    const state = e.state;
    if (!state || !state.tablesPage) {
        window.location.reload();
        return;
    }
    const key = state.tablesPage;
    const $page = $('[data-tables-page]').filter(function () {
        return $(this).attr('data-tables-page') === key;
    }).first();
    if ($page.length === 0) {
        window.location.reload();
        return;
    }
    requestPartial(window.location.href, $page, { push: false });
});
