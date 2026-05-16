// Persistence layer for the in-flight bulk-action tray. Lives in
// localStorage under STORAGE_KEY; capped at STORAGE_LIMIT recent entries.
// Failures (quota, private mode) are logged at warn level — we don't fail
// the caller because progress restoration is best-effort UX.

import { logger } from '../logger.js';

const log = logger.scope('progress.storage');

export const STORAGE_KEY = 'tables.progress.active';
export const STORAGE_LIMIT = 5;

export function loadStored() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        log.warn('loadStored failed', e);
        return [];
    }
}

export function saveStored(items) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) {
        log.warn('saveStored failed', e);
    }
}

export function rememberProgress(opts) {
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

export function forgetProgress(progressId) {
    saveStored(loadStored().filter((p) => p.progressId !== progressId));
}
