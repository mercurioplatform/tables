// Entry point for the tables JS bundle.
//
// Initialization model: each module attaches its delegated handlers to
// $(document) / $(window) at import time (side-effect). The bundle is wired
// once per page via the host's Vite import of resources/js/vendor/tables/;
// no public init() call is required, and dynamically inserted DOM (AJAX,
// portals, modals) is covered automatically by event-delegation.
import './i18n.js';
import './core.js';
import './bulk.js';
import './bulk-form.js';
import './chips.js';
import './autocomplete.js';
import './qb.js';
import './saved-views.js';
import './row-actions.js';
import './cell-edit.js';
import './confirm-preview.js';
import './prefs.js';
import './export.js';
import './filter-groups.js';
import './action-log.js';
import './progress.js';
