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

];
