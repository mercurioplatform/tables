<?php

namespace Mercurio\Tables\Support;

use Illuminate\Http\Request;
use Mercurio\Tables\Models\SavedView as SavedViewModel;

final class UserViewHref
{
    public static function build(Request $request, SavedViewModel $view): string
    {
        $base = $request->url();
        $state = $view->state_json ?? [];
        if (! is_array($state)) {
            $state = [];
        }

        $filtered = array_intersect_key($state, array_flip(TableStateKeys::STATE));
        $filtered['view'] = 'user-'.$view->id;

        $query = http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? $base : $base.'?'.$query;
    }
}
