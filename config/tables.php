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
    | Base URL prefix used by the `Route::tablesResource()` helper macro
    | (registered in iteration E7). Combined with the per-resource path
    | passed to the macro: `Route::tablesResource('catalog/products/v2', ...)`
    | resolves to `<route_prefix>/catalog/products/v2`.
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

];
