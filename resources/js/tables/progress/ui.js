// Pure UI builders for the progress tray: tray locator, progress card,
// status toasts (success / failure / fallback), affected-ids filter URL.
// All HTML is built via jQuery to mirror the rest of the bundle.

import jQuery from 'jquery';
import { tablesT } from '../i18n.js';
import { ATTRS, sel } from '../data-attrs.js';

const $ = jQuery;

export const TRAY_SELECTOR = sel(ATTRS.PROGRESS_TRAY);
export const TOASTS_SELECTOR = sel(ATTRS.PROGRESS_TOASTS);

export function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function ensureTray() {
    const $tray = $(TRAY_SELECTOR).first();
    return $tray.length === 0 ? null : $tray;
}

export function buildCard(opts) {
    const safeLabel = escapeHtml(opts.actionLabel || tablesT('bulk.progress.default_label'));
    const safeId = escapeHtml(opts.progressId);
    const total = Math.max(0, opts.total | 0);
    const $card = $(
        '<div class="tables-progress-card" data-tables-progress-card="' + safeId + '">' +
            '<div class="tables-progress-card__header">' +
                '<div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>' +
                '<div class="tables-progress-card__title">' + safeLabel + '</div>' +
                '<button type="button" class="tables-progress-card__close" data-tables-progress-dismiss aria-label="' +
                    escapeHtml(tablesT('bulk.progress.close')) +
                    '" hidden>' +
                    '<i class="bi bi-x-lg"></i>' +
                '</button>' +
            '</div>' +
            '<div class="tables-progress-card__bar">' +
                '<div class="tables-progress-card__bar-fill" data-tables-progress-fill style="width:0%"></div>' +
            '</div>' +
            '<div class="tables-progress-card__counter" data-tables-progress-counter>0 / ' + total + '</div>' +
        '</div>'
    );
    return $card;
}

export function updateCard($card, data) {
    const total = Math.max(1, data.total | 0);
    const processed = Math.max(0, data.processed | 0);
    const pct = Math.min(100, Math.round((processed / total) * 100));
    $card.find(sel(ATTRS.PROGRESS_FILL)).css('width', pct + '%');
    $card.find(sel(ATTRS.PROGRESS_COUNTER)).text(processed + ' / ' + (data.total | 0));
    if (data.status === 'failed') {
        $card.addClass('is-failed');
    }
}

export function showToast(opts) {
    const $container = $(TOASTS_SELECTOR).first();
    if ($container.length === 0) return;

    const variant = opts.variant || 'success';
    const icon = opts.icon || 'bi-check-circle-fill';
    const cta = opts.cta;
    const ctaHtml = cta && cta.href
        ? '<a href="' + escapeHtml(cta.href) + '" class="btn btn-sm btn-link p-0 ms-2" data-tables-progress-cta>' + escapeHtml(cta.label || tablesT('bulk.progress.open_cta')) + '</a>'
        : '';

    const $toast = $(
        '<div class="toast" role="alert" aria-live="polite" aria-atomic="true" data-bs-autohide="true" data-bs-delay="8000">' +
            '<div class="toast-header">' +
                '<i class="bi ' + escapeHtml(icon) + ' text-' + escapeHtml(variant) + ' me-2"></i>' +
                '<strong class="me-auto">' + escapeHtml(opts.title || '') + '</strong>' +
                '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="' + escapeHtml(tablesT('bulk.progress.close')) + '"></button>' +
            '</div>' +
            '<div class="toast-body">' +
                '<span>' + escapeHtml(opts.body || '') + '</span>' +
                ctaHtml +
            '</div>' +
        '</div>'
    );
    $container.append($toast);

    if (window.bootstrap && window.bootstrap.Toast) {
        const toast = window.bootstrap.Toast.getOrCreateInstance($toast.get(0));
        toast.show();
        $toast.on('hidden.bs.toast', () => $toast.remove());
    }
}

export function showErrorToastFallback(message) {
    const $container = $(TOASTS_SELECTOR).first();
    if ($container.length === 0 || !window.bootstrap || !window.bootstrap.Toast) {
        if (typeof window.alert === 'function') {
            window.alert(message);
        }
        return;
    }
    const $t = $(
        '<div class="toast align-items-center text-bg-danger border-0" role="alert">' +
            '<div class="d-flex">' +
                '<div class="toast-body">' + escapeHtml(message) + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="' + escapeHtml(tablesT('bulk.progress.close')) + '"></button>' +
            '</div>' +
        '</div>'
    );
    $container.append($t);
    window.bootstrap.Toast.getOrCreateInstance($t.get(0)).show();
    $t.on('hidden.bs.toast', () => $t.remove());
}

export function buildAffectedFilterUrl(indexUrl, ids) {
    if (!indexUrl || !Array.isArray(ids) || ids.length === 0) return null;
    try {
        const url = new URL(indexUrl, window.location.origin);
        url.searchParams.delete('f[id][in]');
        url.searchParams.delete('f[id][in][]');
        ids.forEach((id) => {
            url.searchParams.append('f[id][in][]', String(id));
        });
        return url.toString();
    } catch (e) {
        return null;
    }
}
