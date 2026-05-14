import $ from 'jquery';
import { tablesT } from './i18n.js';

const STORAGE_KEY = 'tables.progress.active';
const TRAY_SELECTOR = '[data-tables-progress-tray]';
const TOASTS_SELECTOR = '[data-tables-progress-toasts]';
const STORAGE_LIMIT = 5;

const activePolls = new Map();

function loadStored() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function saveStored(items) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) {
        // localStorage quota / disabled — silent
    }
}

function rememberProgress(opts) {
    const stored = loadStored().filter((p) => p.progressId !== opts.progressId);
    stored.push({
        progressId: opts.progressId,
        progressUrl: opts.progressUrl,
        indexUrl: opts.indexUrl,
        actionLabel: opts.actionLabel,
        total: opts.total,
        startedAt: Date.now(),
    });
    saveStored(stored.slice(-STORAGE_LIMIT));
}

function forgetProgress(progressId) {
    saveStored(loadStored().filter((p) => p.progressId !== progressId));
}

function ensureTray() {
    const $tray = $(TRAY_SELECTOR).first();
    return $tray.length === 0 ? null : $tray;
}

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function buildCard(opts) {
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

function updateCard($card, data) {
    const total = Math.max(1, data.total | 0);
    const processed = Math.max(0, data.processed | 0);
    const pct = Math.min(100, Math.round((processed / total) * 100));
    $card.find('[data-tables-progress-fill]').css('width', pct + '%');
    $card.find('[data-tables-progress-counter]').text(processed + ' / ' + (data.total | 0));
    if (data.status === 'failed') {
        $card.addClass('is-failed');
    }
}

function showToast(opts) {
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

function showErrorToastFallback(message) {
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

function buildAffectedFilterUrl(indexUrl, ids) {
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

function stopPoll(progressId) {
    const poll = activePolls.get(progressId);
    if (!poll) return;
    if (poll.timer) clearTimeout(poll.timer);
    activePolls.delete(progressId);
}

function pollOnce(progressId, opts, $card, $tray) {
    const pollIntervalMs = parseInt($tray.attr('data-poll-interval-ms') || '1500', 10);
    const pollMaxMs = parseInt($tray.attr('data-poll-max-duration-ms') || '600000', 10);

    const poll = activePolls.get(progressId);
    if (!poll) return;

    const elapsed = Date.now() - poll.startedAt;
    if (elapsed > pollMaxMs) {
        stopPoll(progressId);
        forgetProgress(progressId);
        $card.addClass('is-failed');
        $card.find('.spinner-border').remove();
        $card.find('[data-tables-progress-dismiss]').removeAttr('hidden');
        showToast({
            title: opts.actionLabel,
            body: tablesT('bulk.progress.stuck_body'),
            variant: 'danger',
            icon: 'bi-exclamation-triangle-fill',
        });
        return;
    }

    $.ajax({
        url: opts.progressUrl,
        method: 'GET',
        dataType: 'json',
    })
        .done((data) => {
            updateCard($card, data);

            if (data.status === 'done') {
                stopPoll(progressId);
                forgetProgress(progressId);
                $card.find('.spinner-border').remove();
                $card.find('[data-tables-progress-dismiss]').removeAttr('hidden');
                const ids = data.affected_ids_preview || [];
                const ctaUrl = buildAffectedFilterUrl(opts.indexUrl, ids);
                showToast({
                    title: opts.actionLabel,
                    body: tablesT('bulk.progress.success_body', {
                        affected: data.affected | 0,
                        total: data.total | 0,
                    }),
                    variant: 'success',
                    icon: 'bi-check-circle-fill',
                    cta: ctaUrl ? { href: ctaUrl, label: tablesT('bulk.progress.success_cta') } : null,
                });
                setTimeout(() => {
                    $card.addClass('is-fading-out');
                    setTimeout(() => $card.remove(), 200);
                }, 6000);
                return;
            }

            if (data.status === 'failed') {
                stopPoll(progressId);
                forgetProgress(progressId);
                $card.addClass('is-failed');
                $card.find('.spinner-border').remove();
                $card.find('[data-tables-progress-dismiss]').removeAttr('hidden');
                showToast({
                    title: opts.actionLabel,
                    body: data.error_message || tablesT('bulk.progress.failure_body'),
                    variant: 'danger',
                    icon: 'bi-x-circle-fill',
                });
                return;
            }

            const nextTimer = setTimeout(
                () => pollOnce(progressId, opts, $card, $tray),
                pollIntervalMs,
            );
            const current = activePolls.get(progressId);
            if (current) {
                activePolls.set(progressId, Object.assign({}, current, { timer: nextTimer }));
            } else {
                clearTimeout(nextTimer);
            }
        })
        .fail((xhr) => {
            if (xhr && (xhr.status === 403 || xhr.status === 404)) {
                stopPoll(progressId);
                forgetProgress(progressId);
                $card.addClass('is-failed');
                $card.find('.spinner-border').remove();
                $card.find('[data-tables-progress-dismiss]').removeAttr('hidden');
                showToast({
                    title: opts.actionLabel,
                    body: tablesT('bulk.progress.poll_failed', { status: xhr.status }),
                    variant: 'danger',
                    icon: 'bi-exclamation-triangle-fill',
                });
                return;
            }
            const nextTimer = setTimeout(
                () => pollOnce(progressId, opts, $card, $tray),
                pollIntervalMs,
            );
            const current = activePolls.get(progressId);
            if (current) {
                activePolls.set(progressId, Object.assign({}, current, { timer: nextTimer }));
            } else {
                clearTimeout(nextTimer);
            }
        });
}

export function enqueueProgress(opts) {
    if (!opts || !opts.progressId || !opts.progressUrl) {
        // eslint-disable-next-line no-console
        console.warn('[tables.progress] enqueueProgress: progressId and progressUrl required');
        return;
    }

    const $tray = ensureTray();
    if (!$tray) {
        // eslint-disable-next-line no-console
        console.warn('[tables.progress] tray not mounted');
        return;
    }

    if (activePolls.has(opts.progressId)) {
        return;
    }

    const $existing = $('[data-tables-progress-card="' + opts.progressId + '"]');
    let $card;
    if ($existing.length > 0) {
        $card = $existing.first();
    } else {
        $card = buildCard(opts);
        $tray.append($card);
    }

    activePolls.set(opts.progressId, {
        timer: 0,
        startedAt: Date.now(),
        opts,
    });

    rememberProgress(opts);

    pollOnce(opts.progressId, opts, $card, $tray);
}

export function showProgressErrorToast(message) {
    showErrorToastFallback(message);
}

window.TablesProgress = window.TablesProgress || {};
window.TablesProgress.enqueue = enqueueProgress;
window.TablesProgress.errorToast = showProgressErrorToast;

$(function () {
    const $tray = ensureTray();
    if (!$tray) return;
    const stored = loadStored();
    if (stored.length === 0) return;
    const maxMs = parseInt($tray.attr('data-poll-max-duration-ms') || '600000', 10);
    stored.forEach((p) => {
        if (!p || !p.progressId || !p.progressUrl) return;
        if (Date.now() - (p.startedAt || 0) > maxMs) {
            forgetProgress(p.progressId);
            return;
        }
        enqueueProgress({
            progressId: p.progressId,
            progressUrl: p.progressUrl,
            indexUrl: p.indexUrl,
            actionLabel: p.actionLabel,
            total: p.total,
        });
    });
});

$(document).on('click', '[data-tables-progress-dismiss]', function () {
    const $card = $(this).closest('[data-tables-progress-card]');
    $card.remove();
});
