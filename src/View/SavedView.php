<?php

namespace Mercurio\Tables\View;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final class SavedView
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string|Closure|null $scope = null,
    ) {}

    public function apply(Builder $query): void
    {
        if ($this->scope === null) {
            return;
        }

        if (is_string($this->scope)) {
            $query->{$this->scope}();

            return;
        }

        ($this->scope)($query);
    }
}
