// Shared Bootstrap Offcanvas helper.
//
// Centralizes the "el -> bootstrap.Offcanvas.getOrCreateInstance / getInstance"
// dance used in bulk-form.js, row-actions.js, confirm-preview.js, and qb.js.
// All call sites silently no-op when window.bootstrap is unavailable - same as
// before refactor; this is intentional (host pages may load tables.js outside
// admin context where Bootstrap is not bundled).

function resolveEl(target) {
    if (!target) return null;
    if (typeof target === 'string') return document.getElementById(target);
    if (target.nodeType === 1) return target;
    if (target.jquery) return target.get(0);
    return null;
}

function getBootstrapOffcanvas() {
    return window.bootstrap?.Offcanvas || null;
}

export function showOffcanvas(target) {
    const el = resolveEl(target);
    if (!el) return null;
    const Offcanvas = getBootstrapOffcanvas();
    if (!Offcanvas) return null;
    const instance = Offcanvas.getOrCreateInstance(el);
    instance.show();
    return instance;
}

export function hideOffcanvas(target) {
    const el = resolveEl(target);
    if (!el) return;
    const Offcanvas = getBootstrapOffcanvas();
    if (!Offcanvas) return;
    Offcanvas.getInstance(el)?.hide();
}
