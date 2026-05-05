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

];
