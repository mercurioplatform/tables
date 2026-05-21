import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { logger } from './logger.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

const inflight = new WeakMap();

function isModifiedClick(e) {
    return e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0;
}

function syncSavedViews($page, url) {
    const params = new URL(url, window.location.origin).searchParams;
    const view = params.get('view');
    const $links = $page.find('a' + sel(ATTRS.SAVED_VIEW) + sel(ATTRS.SAVED_VIEW_KEY));
    if ($links.length === 0) return;

    $links.removeClass('is-active');

    let activated = false;
    if (view !== null && view !== '') {
        const $match = $links.filter(sel(ATTRS.SAVED_VIEW_KEY, view));
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

    const $root = $page.find(sel(ATTRS.ROOT)).first();
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
        const newRoot = $(doc).find(sel(ATTRS.ROOT)).get(0);
        if (!newRoot) {
            logger.error('response has no [data-tables-root]');
            return;
        }
        const oldRoot = $root.get(0);
        oldRoot.replaceWith(newRoot);

        const newFilterBar = $(doc).find(sel(ATTRS.FILTER_BAR)).get(0);
        if (newFilterBar) {
            const $oldFilterBar = $page.find(sel(ATTRS.FILTER_BAR)).first();
            if ($oldFilterBar.length > 0) {
                $oldFilterBar.get(0).replaceWith(newFilterBar);
            }
        }

        const newSavedViews = $(doc).find(sel(ATTRS.SAVED_VIEWS)).get(0);
        if (newSavedViews) {
            const $oldSavedViews = $page.find(sel(ATTRS.SAVED_VIEWS)).first();
            if ($oldSavedViews.length > 0) {
                $oldSavedViews.get(0).replaceWith(newSavedViews);
            }
        }

        const newSummary = $(doc).find(sel(ATTRS.SUMMARY)).get(0);
        if (newSummary) {
            const $oldSummary = $page.find(sel(ATTRS.SUMMARY)).first();
            if ($oldSummary.length > 0) {
                $oldSummary.get(0).replaceWith(newSummary);
            }
        }

        // Subtitle живёт в shell снаружи [data-tables-page], поэтому ищем его
        // глобально в документе.
        const newSubtitle = $(doc).find(sel(ATTRS.SUBTITLE)).get(0);
        const $oldSubtitle = $(sel(ATTRS.SUBTITLE)).first();
        if (newSubtitle && $oldSubtitle.length > 0) {
            const text = newSubtitle.textContent || '';
            $oldSubtitle.text(text).toggleClass('d-none', text.trim() === '');
        }

        // Public total-changed event
        const newTotalAttr = $(newRoot).attr(ATTRS.TOTAL);
        if (newTotalAttr !== null && newTotalAttr !== undefined) {
            const total = parseInt(newTotalAttr, 10);
            if (!Number.isNaN(total)) {
                $page.attr(ATTRS.TOTAL, String(total));
                // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
                document.dispatchEvent(new CustomEvent(EVENTS.TOTAL_CHANGED, {
                    detail: {
                        resource: $page.attr(ATTRS.PAGE),
                        total,
                        subtitle: newSubtitle?.textContent ?? null,
                    },
                }));
            }
        }

        syncSavedViews($page, url);
        $(newRoot).trigger(EVENTS.RENDERED);
        // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
        document.dispatchEvent(new CustomEvent(EVENTS.RENDERED, { detail: { url } }));
        if (push) {
            window.history.pushState(
                { tablesPage: $page.attr(ATTRS.PAGE), url: url },
                '',
                url,
            );
        }
    });

    xhr.fail(function (jqXHR) {
        if (jqXHR.statusText === 'abort') return;
        logger.error('ajax failed', jqXHR.status, jqXHR.statusText);
    });

    xhr.always(function () {
        $page.find(sel(ATTRS.ROOT)).first().removeClass('is-loading');
        if (inflight.get(pageEl) === xhr) {
            inflight.delete(pageEl);
        }
    });
}

$(document).on('submit', 'form' + sel(ATTRS.SEARCH_FORM), function (e) {
    const $form = $(this);
    const $page = $form.closest(sel(ATTRS.PAGE));
    if ($page.length === 0) return;
    e.preventDefault();
    const query = $form.serialize();
    const action = $form.attr('action') || window.location.pathname;
    const url = action + (query ? '?' + query : '');
    requestPartial(url, $page);
});

$(document).on('keydown', 'form' + sel(ATTRS.SEARCH_FORM) + ' input[name="q"]', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    $(this).closest('form' + sel(ATTRS.SEARCH_FORM)).trigger('submit');
});

$(document).on('click', 'a' + sel(ATTRS.SAVED_VIEW), function (e) {
    const $a = $(this);
    const $page = $a.closest(sel(ATTRS.PAGE));
    if ($page.length === 0) return;
    if (isModifiedClick(e)) return;
    const href = $a.attr('href');
    if (!href) return;
    e.preventDefault();
    requestPartial(href, $page);
});

$(document).on('click', sel(ATTRS.ROOT) + ' thead a[href]', function (e) {
    const $a = $(this);
    const $page = $a.closest(sel(ATTRS.PAGE));
    if ($page.length === 0) return;
    if (isModifiedClick(e)) return;
    e.preventDefault();
    requestPartial($a.attr('href'), $page);
});

$(document).on('click', sel(ATTRS.ROOT) + ' .pagination a[href]', function (e) {
    const $a = $(this);
    const $page = $a.closest(sel(ATTRS.PAGE));
    if ($page.length === 0) return;
    if (isModifiedClick(e)) return;
    e.preventDefault();
    requestPartial($a.attr('href'), $page);
});

// Public DOM event listener — keep native addEventListener (symmetric to native dispatchEvent above).
document.addEventListener(EVENTS.NAVIGATE, function (e) {
    const url = e?.detail?.url;
    if (!url) return;
    const push = e?.detail?.push !== false;
    const $page = $(sel(ATTRS.PAGE)).first();
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
    const $page = $(sel(ATTRS.PAGE, key)).first();
    if ($page.length === 0) {
        window.location.reload();
        return;
    }
    requestPartial(window.location.href, $page, { push: false });
});
