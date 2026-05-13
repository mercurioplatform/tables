<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth Guard
    |--------------------------------------------------------------------------
    |
    | Default auth guard used by the engine for policy/Gate checks, user
    | prefs, saved views, exports, cell-edit policies, and audit log.
    | Override per-Resource via `ListResource::guard(): ?string` — return
    | a guard name to override this default, or null to use it as-is.
    | Useful for multi-guard pages (e.g. admin + web storefront on one
    | screen).
    |
    */
    'guard' => 'admin',

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | Optional prefix kept for documentation / future helpers.
    | The `Route::tablesResource()` macro does NOT auto-prepend this — use
    | the surrounding `Route::prefix(...)->group(...)` of the host application.
    | This config is consumed by future helpers (export URLs, self-links)
    | when needed.
    |
    */
    'route_prefix' => 'admin',

    /*
    |--------------------------------------------------------------------------
    | Default Page Size
    |--------------------------------------------------------------------------
    |
    | Items per page when a Resource does not declare its own `perPage`.
    |
    */
    'default_per_page' => 25,

    /*
    |--------------------------------------------------------------------------
    | AJAX Partial Header
    |--------------------------------------------------------------------------
    |
    | HTTP request header consumed by the engine controller trait
    | (E7-http-trait): when set, the controller renders only the
    | `<x-tables.table-root>` fragment instead of the full page.
    |
    */
    'partial_header' => 'X-Tables-Partial',

    /*
    |--------------------------------------------------------------------------
    | JS Event Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used by the JS core (E8-js-core) for custom events:
    | `<prefix>:rendered`, `<prefix>:loading`, etc.
    |
    */
    'js_event_prefix' => 'tables',

    /*
    |--------------------------------------------------------------------------
    | Filter Autocomplete
    |--------------------------------------------------------------------------
    |
    | Settings for the relation autocomplete popover (Tables/2.4).
    |   - autocomplete_limit:        max items returned by /options endpoint.
    |   - autocomplete_min_chars:    min input length before XHR (0 = open on focus).
    |   - autocomplete_debounce_ms:  delay before issuing XHR after input change.
    |   - route_options_suffix:      suffix appended to resource path for the JSON route.
    |
    */
    'autocomplete_limit' => 50,
    'autocomplete_min_chars' => 0,
    'autocomplete_debounce_ms' => 250,
    'route_options_suffix' => '/options',

    /*
    |--------------------------------------------------------------------------
    | Query Builder (Tables/2.5)
    |--------------------------------------------------------------------------
    |
    | Limits and defaults for the AST-based advanced filter (`?qb=base64(json)`).
    |   - qb_max_payload_size:   max length (bytes) of the base64 query string.
    |   - qb_max_depth:          max nesting depth of group nodes.
    |   - qb_max_atoms:          max total atomic conditions in the tree.
    |   - qb_button_label:       label of the trigger button in filter-bar.
    |   - qb_offcanvas_width:    CSS class applied to the offcanvas root.
    |
    */
    'qb_max_payload_size' => 4096,
    'qb_max_depth' => 5,
    'qb_max_atoms' => 100,
    'qb_button_label' => 'Расширенный фильтр',
    'qb_offcanvas_width' => 'qb-offcanvas-md',

    /*
    |--------------------------------------------------------------------------
    | Saved Views (Tables/2.6)
    |--------------------------------------------------------------------------
    |
    | Settings for the unified saved-views storage (system + user views).
    |   - sync_system_views:           toggles SystemViewSyncer auto-call in TablesServiceProvider::boot().
    |   - resources:                   array of FQN ListResource classes for ResourceRegistry (optional).
    |   - saved_view_color_palette:    whitelist of color keys allowed in saveView endpoint.
    |   - saved_view_icons:            whitelist of Bootstrap Icons names allowed in saveView endpoint.
    |
    */
    'sync_system_views' => true,
    'resources' => [],
    'saved_view_color_palette' => ['neutral', 'blue', 'green', 'amber', 'red', 'purple'],
    'saved_view_icons' => ['bi-bookmark', 'bi-star', 'bi-flag', 'bi-funnel', 'bi-tag', 'bi-eye', 'bi-archive'],

    /*
    |--------------------------------------------------------------------------
    | Row Actions (Tables/2.7)
    |--------------------------------------------------------------------------
    |
    | Path suffixes used by `Route::tablesResource()` for the row-action
    | endpoints declared via `RowAction` VO.
    |   - suffix:        POST endpoint base (kind=instant|confirm|form submit).
    |   - form_suffix:   GET endpoint suffix for kind=form partial render.
    |
    */
    'row_actions' => [
        'suffix' => '/row-action',
        'form_suffix' => '/form',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk Actions (Tables/2.8)
    |--------------------------------------------------------------------------
    |
    | Path used by `Route::tablesResource()` for the bulk-action form GET
    | endpoint declared via `BulkAction::form(...)`. Currently fixed to
    | `/bulk-action/{action}/form` — entry kept for documentation and
    | future extensibility (engine itself uses the hardcoded path).
    |
    */
    'bulk_actions' => [
        'form_suffix' => '/bulk-action/{action}/form',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cell Edit (Tables/3.9)
    |--------------------------------------------------------------------------
    |
    | Inline single-cell editing для editable-полей. Регистрируется
    | автоматически через `Route::tablesResource()` как
    | PATCH {base}{suffix}/{id}/{field}. Включается на уровне поля через
    | `Field::editable()` + опциональный `editPolicy(...)`.
    |   - suffix:               URL-suffix перед {id}/{field}.
    |   - embed_options_limit:  максимум options для select-инпутов,
    |                           которые embed'дятся в data-options;
    |                           при превышении эмиттится warning-лог.
    |
    */
    'cell_edit' => [
        'suffix' => '/cells',
        'embed_options_limit' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | User Table Prefs (Tables/2.9)
    |--------------------------------------------------------------------------
    |
    | Per-user persisted preferences for table listing (visible columns,
    | density, page size). Stored in `tables_user_table_prefs` keyed by
    | (user_id, resource_key). URL query params override DB; DB overrides
    | Resource defaults.
    |   - per_page_options:        whitelist of allowed page sizes shown in popover.
    |   - density_options:         whitelist of allowed density modes.
    |   - popover_button_label:    label of the trigger button in filter-bar:right.
    |   - popover_button_icon:     Bootstrap Icons class for the button.
    |
    */
    'user_prefs' => [
        'per_page_options' => [15, 25, 50, 100],
        'density_options' => ['compact', 'comfortable'],
        'popover_button_label' => 'Настроить таблицу',
        'popover_button_icon' => 'bi-gear',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export (Tables/2.10)
    |--------------------------------------------------------------------------
    |
    | Streaming CSV export of the current listing state (search + view +
    | chip filters + qb + sort + visible columns). Implemented as a GET
    | endpoint registered automatically by `Route::tablesResource()`.
    |   - sync_limit:       max rows for synchronous streamed export.
    |                       Above this the engine refuses with HTTP 413
    |                       and (when configured) hands off to async dispatcher.
    |   - chunk_size:       rows per chunkById iteration when streaming.
    |   - csv_delimiter:    column delimiter (',', ';', '\t').
    |   - csv_enclosure:    enclosure character ('"').
    |   - csv_escape:       escape character ('\').
    |   - csv_bom:          prepend UTF-8 BOM (true → Excel-friendly).
    |   - filename_prefix:  prefix prepended to <slug>-<timestamp>.csv.
    |   - ability:          Gate ability checked before export. null = skip.
    |   - async_dispatcher: optional FQCN implementing
    |                       Mercurio\Tables\Export\ExportJobDispatcher.
    |                       Engine calls dispatch() when total > sync_limit.
    |                       null = no async fallback (engine returns 413).
    |   - log_chunks:       emit Log::debug per chunk (verbose, off by default).
    |   - button_label:     label of the Export button in filter-bar:right.
    |   - button_icon:      Bootstrap Icons class for the button.
    |
    */
    'export' => [
        'sync_limit' => 10000,
        'chunk_size' => 500,
        'csv_delimiter' => ',',
        'csv_enclosure' => '"',
        'csv_escape' => '\\',
        'csv_bom' => true,
        'filename_prefix' => '',
        'ability' => null,
        'async_dispatcher' => null,
        'log_chunks' => false,
        'button_label' => 'Экспорт',
        'button_icon' => 'bi-download',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shell (Tables/3.1)
    |--------------------------------------------------------------------------
    |
    | Page-shell rendering settings. The engine wraps every list page in a
    | reusable shell view (`tables::shell`) which @extends a Blade layout,
    | pushes breadcrumbs, sets <title>, renders the page-head and flashes,
    | and hosts <x-tables.page>. Resource declares page-level data via
    | `ListResource::pageTitle()` / `browserTitle()` / `subtitle($total)` /
    | `headerActions()` / `breadcrumbs()` / `flashKeys()`.
    |   - layout:               Blade layout the shell @extends.
    |   - page_head_component:  Blade x-component used to render the page-head
    |                           (title + actions). Use a project component to
    |                           keep visual parity, or fall back to engine's
    |                           own `tables::page-head` (zero-CSS dependency).
    |   - flash_keys:           Map session-key => bootstrap alert variant.
    |                           Engine renders alerts iff `session()->has(key)`.
    |                           ListResource::flashKeys() may override.
    |   - title_suffix:         Optional sprintf-style suffix appended to
    |                           <title> ("· %s · Admin"). null = no suffix
    |                           (host layout is expected to add its own).
    |
    */
    'shell' => [
        'layout' => 'admin.layouts.app',
        'page_head_component' => 'admin.page-head',
        'flash_keys' => [
            'status' => 'success',
            'warning' => 'warning',
            'error' => 'danger',
        ],
        'title_suffix' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Log (Tables/3.12)
    |--------------------------------------------------------------------------
    |
    | Append-only audit log of successful bulk/row-actions. Written by the
    | engine controller trait after a non-throwing ActionResult. Read via
    | the `tables.action_log` route — rendered into the per-resource
    | offcanvas «История» when ListResource::actionHistoryEnabled() = true.
    |   - enabled:                 master kill-switch for the writer.
    |   - subjects_id_limit:       max ids stored in subjects_json before falling
    |                              back to count-only.
    |   - recent_limit:            window of newest rows visible in the offcanvas.
    |   - per_page:                rows per page inside the offcanvas paginator.
    |   - header_action_label:     label of the auto-injected HeaderAction.
    |   - header_action_icon:      Bootstrap Icons class for the header action.
    |   - payload_max_bytes:       soft cap on serialized payload_json size; over the
    |                              cap engine stores `{_truncated: true, size: N}`
    |                              and emits a Log::warning.
    |
    */
    'action_log' => [
        'enabled' => true,
        'subjects_id_limit' => 100,
        'recent_limit' => 200,
        'per_page' => 25,
        'header_action_label' => 'История',
        'header_action_icon' => 'bi-clock-history',
        'payload_max_bytes' => 16384,
        'undo_window_minutes' => 60,
        'undo_snapshot_max_bytes' => 65536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk Progress (Tables/3.13)
    |--------------------------------------------------------------------------
    |
    | Async execution of opt-in BulkAction's via Laravel Queue.
    | Action declares `->queue()` → engine returns 202 + progress_id; JS polls
    | `{base}.action_progress` and renders progress card + completion toast.
    |   - enabled:                    master kill-switch (false → all `->queue()` ignored, sync path).
    |   - default_chunk_size:         fallback when BulkAction::queueChunkSize() not set.
    |                                 0 = no chunking (handler invoked once with full ids).
    |   - default_threshold:          fallback for BulkAction::queueWhen(); null = always queue.
    |   - poll_interval_ms:           JS poller cadence.
    |   - poll_max_duration_ms:       hard cap before JS gives up («action stalled»).
    |   - progress_ttl_minutes:       informational TTL (no auto-pruning by engine; host responsibility).
    |   - max_affected_ids_for_cta:   cap on affected_ids_json size; CTA hidden if exceeded.
    |   - job_class:                  FQCN of ShouldQueue-job. Override for custom backoff/retries.
    |   - job_tries:                  max attempts (1 = no retry; failure → status=failed).
    |   - job_timeout_seconds:        per-attempt timeout in seconds.
    |   - tray_position:              CSS class hook for the tray container.
    |
    */
    'bulk_progress' => [
        'enabled' => true,
        'default_chunk_size' => 0,
        'default_threshold' => null,
        'poll_interval_ms' => 1500,
        'poll_max_duration_ms' => 600000,
        'progress_ttl_minutes' => 1440,
        'max_affected_ids_for_cta' => 200,
        'job_class' => \Mercurio\Tables\Jobs\BulkActionJob::class,
        'job_tries' => 1,
        'job_timeout_seconds' => 600,
        'tray_position' => 'bottom-right',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names (Tables/3.14)
    |--------------------------------------------------------------------------
    |
    | Имена БД-таблиц внутреннего состояния движка. Параметризованы для
    | escape hatch — если хост-проект уже занял имя или хочет иную схему,
    | публикуется тег `tables-migrations` и/или переопределяется этот блок.
    | Дефолты — единый префикс `tables_`.
    |
    */
    'tables' => [
        'saved_views'      => 'tables_saved_views',
        'user_table_prefs' => 'tables_user_table_prefs',
        'action_log'       => 'tables_action_log',
        'action_progress'  => 'tables_action_progress',
    ],

];
