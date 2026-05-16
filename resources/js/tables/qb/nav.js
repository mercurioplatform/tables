// Public QB actions: apply / reset / clearFromOutside. These dispatch the
// host-facing tables:navigate event (CustomEvent) with the rebuilt URL.
// Also hosts the $root <-> attribute accessors so handlers and form-sync
// share a single source of truth for AST/schema reads.

import { ATTRS, EVENTS, sel } from '../data-attrs.js';
import { hideOffcanvas } from '../offcanvas.js';
import { utf8Btoa } from './serialization.js';
import { emptyGroup, countAtoms } from './ast.js';
import { readFormValues } from './form-sync.js';

export function getRoot($el) {
    return $el.closest(sel(ATTRS.QB_ROOT));
}

export function getState($root) {
    const raw = $root.attr(ATTRS.QB_STATE) || '';
    if (raw === '' || raw === '{}' || raw === '[]') {
        return emptyGroup();
    }
    try {
        const parsed = JSON.parse(raw);
        if (parsed && parsed.type === 'group') {
            return parsed;
        }
        if (parsed && parsed.type === 'cond') {
            return { type: 'group', op: 'AND', not: false, children: [parsed] };
        }
    } catch (e) {
        // fall through
    }
    return emptyGroup();
}

export function setState($root, ast) {
    $root.attr(ATTRS.QB_STATE, JSON.stringify(ast));
}

export function getSchema($root) {
    const raw = $root.attr(ATTRS.QB_SCHEMA) || '{"fields":[]}';
    try {
        return JSON.parse(raw);
    } catch (e) {
        return { fields: [] };
    }
}

export function buildUrl(params) {
    const qs = params.toString();
    return window.location.pathname + (qs ? '?' + qs : '');
}

export function applyState($root) {
    const state = getState($root);
    readFormValues($root, state);
    setState($root, state);

    const params = new URLSearchParams(window.location.search);
    const atoms = countAtoms(state);
    if (atoms === 0) {
        params.delete('qb');
    } else {
        params.set('qb', utf8Btoa(JSON.stringify(state)));
    }
    params.delete('page');

    hideOffcanvas($root.closest('.offcanvas'));

    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url: buildUrl(params) } }));
}

export function resetStateAndNavigate($root, renderAfter) {
    setState($root, emptyGroup());
    if (typeof renderAfter === 'function') {
        renderAfter($root);
    }

    const params = new URLSearchParams(window.location.search);
    params.delete('qb');
    params.delete('page');

    hideOffcanvas($root.closest('.offcanvas'));

    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url: buildUrl(params) } }));
}

export function clearFromOutside() {
    const params = new URLSearchParams(window.location.search);
    params.delete('qb');
    params.delete('page');
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url: buildUrl(params) } }));
}
