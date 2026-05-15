import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';

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
        const $match = $links.filter('[data-tables-saved-view-key="' + view + '"]');
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

    const xhr = tablesAjax({
        url: url,
        method: 'GET',
        dataType: 'html',
    });

    inflight.set(pageEl, xhr);

    xhr.done(function (html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newRoot = $(doc).find('[data-tables-root]').get(0);
        if (!newRoot) {
            console.error('[tables] response has no [data-tables-root]');
            return;
        }
        const oldRoot = $root.get(0);
        oldRoot.replaceWith(newRoot);

        const newFilterBar = $(doc).find('[data-tables-filter-bar]').get(0);
        if (newFilterBar) {
            const $oldFilterBar = $page.find('[data-tables-filter-bar]').first();
            if ($oldFilterBar.length > 0) {
                $oldFilterBar.get(0).replaceWith(newFilterBar);
            }
        }

        const newSavedViews = $(doc).find('[data-tables-saved-views]').get(0);
        if (newSavedViews) {
            const $oldSavedViews = $page.find('[data-tables-saved-views]').first();
            if ($oldSavedViews.length > 0) {
                $oldSavedViews.get(0).replaceWith(newSavedViews);
            }
        }

        const newSummary = $(doc).find('[data-tables-summary]').get(0);
        if (newSummary) {
            const $oldSummary = $page.find('[data-tables-summary]').first();
            if ($oldSummary.length > 0) {
                $oldSummary.get(0).replaceWith(newSummary);
            }
        }

        // Subtitle update (Tables/3.1) — subtitle живёт в shell снаружи [data-tables-page],
        // поэтому ищем его глобально в документе.
        const newSubtitle = $(doc).find('[data-tables-subtitle]').get(0);
        const $oldSubtitle = $('[data-tables-subtitle]').first();
        if (newSubtitle && $oldSubtitle.length > 0) {
            const text = newSubtitle.textContent || '';
            $oldSubtitle.text(text).toggleClass('d-none', text.trim() === '');
        }

        // Public total-changed event (Tables/3.1)
        const newTotalAttr = $(newRoot).attr('data-tables-total');
        if (newTotalAttr !== null && newTotalAttr !== undefined) {
            const total = parseInt(newTotalAttr, 10);
            if (!Number.isNaN(total)) {
                $page.attr('data-tables-total', String(total));
                // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
                document.dispatchEvent(new CustomEvent('tables:total-changed', {
                    detail: {
                        resource: $page.attr('data-tables-page'),
                        total,
                        subtitle: newSubtitle?.textContent ?? null,
                    },
                }));
            }
        }

        syncSavedViews($page, url);
        $(newRoot).trigger('tables:rendered');
        // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
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

$(document).on('keydown', 'form[data-tables-search-form] input[name="q"]', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    $(this).closest('form[data-tables-search-form]').trigger('submit');
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

// Public DOM event listener — keep native addEventListener (symmetric to native dispatchEvent above).
document.addEventListener('tables:navigate', function (e) {
    const url = e?.detail?.url;
    if (!url) return;
    const push = e?.detail?.push !== false;
    const $page = $('[data-tables-page]').first();
    if ($page.length === 0) return;
    requestPartial(url, $page, { push });
});

window.addEventListener('popstate', function (e) {
    const state = e.state;
    if (!state || !state.tablesPage) {
        window.location.reload();
        return;
    }
    const key = state.tablesPage;
    const $page = $('[data-tables-page="' + key + '"]').first();
    if ($page.length === 0) {
        window.location.reload();
        return;
    }
    requestPartial(window.location.href, $page, { push: false });
});
