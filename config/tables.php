<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth Guard
    |--------------------------------------------------------------------------
    |
    | Guard name used by the engine for policy/Gate checks in BulkAction
    | and RowAction handlers. Override in published config when an app
    | uses a different admin guard.
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
    | User Table Prefs (Tables/2.9)
    |--------------------------------------------------------------------------
    |
    | Per-user persisted preferences for table listing (visible columns,
    | density, page size). Stored in `user_table_prefs` keyed by
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

];
