// Orchestrator for the bulk-action progress tray. Public entry points:
//   window.TablesProgress.enqueue(opts)   — start polling for a progressId
//   window.TablesProgress.errorToast(msg) — danger-toast fallback
// Storage / UI / polling state-machine live in ./progress/*.

import $ from 'jquery';
import { logger } from './logger.js';
import { ATTRS, sel } from './data-attrs.js';
import { loadStored, forgetProgress, rememberProgress } from './progress/storage.js';
import { buildCard, ensureTray, showErrorToastFallback } from './progress/ui.js';
import {
    POLL_MAX_DEFAULT_MS,
    activePolls,
    pollOnce,
} from './progress/poller.js';

const log = logger.scope('progress');

export function enqueueProgress(opts) {
    if (!opts || !opts.progressId || !opts.progressUrl) {
        log.error('enqueueProgress: progressId and progressUrl required');
        return;
    }

    const $tray = ensureTray();
    if (!$tray) {
        log.error('tray not mounted');
        return;
    }

    if (activePolls.has(opts.progressId)) {
        return;
    }

    const $existing = $(sel(ATTRS.PROGRESS_CARD, opts.progressId));
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
    const maxMs = parseInt($tray.attr('data-poll-max-duration-ms') || String(POLL_MAX_DEFAULT_MS), 10);
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

$(document).on('click', sel(ATTRS.PROGRESS_DISMISS), function () {
    const $card = $(this).closest(sel(ATTRS.PROGRESS_CARD));
    $card.remove();
});
