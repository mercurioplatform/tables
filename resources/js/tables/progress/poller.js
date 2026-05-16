// Background poller for action-progress endpoints. Holds the activePolls
// registry and the state machine: GET → update card → either reschedule,
// stop on done/failed, or stop on stuck (poll-max budget exceeded).
//
// Retry-on-soft-error and reschedule-after-tick share scheduleNext() so
// the two timer paths can't drift.

import { tablesAjax } from '../ajax.js';
import { tablesT } from '../i18n.js';
import { ATTRS, sel } from '../data-attrs.js';
import { forgetProgress } from './storage.js';
import {
    buildAffectedFilterUrl,
    showToast,
    updateCard,
} from './ui.js';

export const POLL_INTERVAL_DEFAULT_MS = 1500;
export const POLL_MAX_DEFAULT_MS = 600000;
export const FADE_OUT_DELAY_MS = 6000;
const FADE_REMOVE_DELAY_MS = 200;

export const activePolls = new Map();

export function stopPoll(progressId) {
    const poll = activePolls.get(progressId);
    if (!poll) return;
    if (poll.timer) clearTimeout(poll.timer);
    activePolls.delete(progressId);
}

function readPollInterval($tray) {
    return parseInt($tray.attr('data-poll-interval-ms') || String(POLL_INTERVAL_DEFAULT_MS), 10);
}

function readPollMaxDuration($tray) {
    return parseInt($tray.attr('data-poll-max-duration-ms') || String(POLL_MAX_DEFAULT_MS), 10);
}

function markCardFinal($card) {
    $card.find('.spinner-border').remove();
    $card.find(sel(ATTRS.PROGRESS_DISMISS)).removeAttr('hidden');
}

function scheduleNext(progressId, opts, $card, $tray, pollIntervalMs) {
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
}

export function pollOnce(progressId, opts, $card, $tray) {
    const pollIntervalMs = readPollInterval($tray);
    const pollMaxMs = readPollMaxDuration($tray);

    const poll = activePolls.get(progressId);
    if (!poll) return;

    const elapsed = Date.now() - poll.startedAt;
    if (elapsed > pollMaxMs) {
        stopPoll(progressId);
        forgetProgress(progressId);
        $card.addClass('is-failed');
        markCardFinal($card);
        showToast({
            title: opts.actionLabel,
            body: tablesT('bulk.progress.stuck_body'),
            variant: 'danger',
            icon: 'bi-exclamation-triangle-fill',
        });
        return;
    }

    tablesAjax({
        url: opts.progressUrl,
        method: 'GET',
        dataType: 'json',
        partial: false,
    })
        .done((data) => {
            updateCard($card, data);

            if (data.status === 'done') {
                stopPoll(progressId);
                forgetProgress(progressId);
                markCardFinal($card);
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
                    setTimeout(() => $card.remove(), FADE_REMOVE_DELAY_MS);
                }, FADE_OUT_DELAY_MS);
                return;
            }

            if (data.status === 'failed') {
                stopPoll(progressId);
                forgetProgress(progressId);
                $card.addClass('is-failed');
                markCardFinal($card);
                showToast({
                    title: opts.actionLabel,
                    body: data.error_message || tablesT('bulk.progress.failure_body'),
                    variant: 'danger',
                    icon: 'bi-x-circle-fill',
                });
                return;
            }

            scheduleNext(progressId, opts, $card, $tray, pollIntervalMs);
        })
        .fail((xhr) => {
            if (xhr && (xhr.status === 403 || xhr.status === 404)) {
                stopPoll(progressId);
                forgetProgress(progressId);
                $card.addClass('is-failed');
                markCardFinal($card);
                showToast({
                    title: opts.actionLabel,
                    body: tablesT('bulk.progress.poll_failed', { status: xhr.status }),
                    variant: 'danger',
                    icon: 'bi-exclamation-triangle-fill',
                });
                return;
            }
            scheduleNext(progressId, opts, $card, $tray, pollIntervalMs);
        });
}

