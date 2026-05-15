import jQuery from 'jquery';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

const STORAGE_PREFIX = 'tables:filter-groups:';

function readStorage(resourceKey) {
    try {
        const raw = window.localStorage.getItem(STORAGE_PREFIX + resourceKey);
        if (!raw) return {};
        const obj = JSON.parse(raw);
        return obj && typeof obj === 'object' ? obj : {};
    } catch (e) {
        return {};
    }
}

function writeStorage(resourceKey, state) {
    try {
        window.localStorage.setItem(STORAGE_PREFIX + resourceKey, JSON.stringify(state));
    } catch (e) {
        // quota / privacy mode — ignore
    }
}

function applyState($bar) {
    const resourceKey = $bar.attr(ATTRS.FILTER_BAR);
    if (!resourceKey) return;
    const state = readStorage(resourceKey);

    $bar.find(sel(ATTRS.FILTER_GROUP)).each(function () {
        const $group = $(this);
        const key = $group.attr(ATTRS.FILTER_GROUP) || '';
        const hasActive = $group.hasClass('tables-filter-group--has-active');
        const $body = $group.find('.tables-filter-group__body').first();
        const $toggle = $group.find('.tables-filter-group__toggle').first();

        let shouldBeOpen;
        if (hasActive) {
            shouldBeOpen = true;
        } else if (Object.prototype.hasOwnProperty.call(state, key)) {
            shouldBeOpen = state[key] === 'open';
        } else {
            shouldBeOpen = true;
        }

        if (shouldBeOpen) {
            $body.addClass('show');
            $toggle.attr('aria-expanded', 'true');
        } else {
            $body.removeClass('show');
            $toggle.attr('aria-expanded', 'false');
        }
    });
}

function initAll() {
    $(sel(ATTRS.FILTER_BAR_GROUPED, '1')).each(function () {
        applyState($(this));
    });
}

// BS5 events are dispatched via native CustomEvent with literal type
// 'shown.bs.collapse' / 'hidden.bs.collapse'. jQuery namespaced .on() does not
// match these because it splits on '.' and listens to 'shown' / 'hidden' only.
// addEventListener stays native; the guards/lookups inside are on jQuery.
function onCollapseEvent(e) {
    const $target = $(e.target);
    if (!$target.hasClass('tables-filter-group__body')) {
        return;
    }
    const $group = $target.closest(sel(ATTRS.FILTER_GROUP));
    if ($group.length === 0) return;
    const $bar = $target.closest(sel(ATTRS.FILTER_BAR));
    if ($bar.length === 0) return;
    const resourceKey = $bar.attr(ATTRS.FILTER_BAR);
    const key = $group.attr(ATTRS.FILTER_GROUP) || '';
    if (!resourceKey) return;
    const state = readStorage(resourceKey);
    state[key] = e.type === 'shown.bs.collapse' ? 'open' : 'closed';
    writeStorage(resourceKey, state);
}

document.addEventListener('shown.bs.collapse', onCollapseEvent);
document.addEventListener('hidden.bs.collapse', onCollapseEvent);

$(initAll);
$(document).on(EVENTS.RENDERED, initAll);
