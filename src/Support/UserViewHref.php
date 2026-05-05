<?php

namespace Mercurio\Tables\Support;

use Illuminate\Http\Request;
use Mercurio\Tables\Models\SavedView as SavedViewModel;

final class UserViewHref
{
    private const WHITELIST = ['q', 'view', 'f', 'qb', 'sort', 'dir', 'columns', 'density', 'per_page'];

    public static function build(Request $request, SavedViewModel $view): string
    {
        $base = $request->url();
        $state = $view->state_json ?? [];
        if (! is_array($state)) {
            $state = [];
        }

        $filtered = array_intersect_key($state, array_flip(self::WHITELIST));
        unset($filtered['view']);
        $filtered['view'] = 'user-'.$view->id;

        $query = http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? $base : $base.'?'.$query;
    }
}
