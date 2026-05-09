<?php

namespace Mercurio\Tables\Support;

final class TableStateKeys
{
    /**
     * Whitelist of saved-view URL state keys.
     *
     * Keep in sync with `packages/tables/resources/js/tables/saved-views.js::STATE_WHITELIST`.
     *
     * Note: `view` is a navigator key (selects which saved view is active),
     * not part of the state itself, so it MUST NOT appear here.
     */
    public const STATE = ['q', 'f', 'qb', 'sort', 'dir', 'columns', 'density', 'per_page'];
}
