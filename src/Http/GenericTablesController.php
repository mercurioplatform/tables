<?php

namespace Mercurio\Tables\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Concerns\HandlesResourceListing;

final class GenericTablesController
{
    use HandlesResourceListing;

    /** @var class-string */
    protected string $resource;

    public function __construct(Request $request)
    {
        $route = $request->route();

        if ($route === null) {
            return;
        }

        $resourceClass = $route->defaults['resource'] ?? null;

        if (! is_string($resourceClass) || ! class_exists($resourceClass)) {
            throw new \LogicException(
                'GenericTablesController: route is missing "resource" default — register the route via Route::tablesPage().',
            );
        }

        $this->resource = $resourceClass;

        Log::debug('tables.generic_controller.boot', [
            'resource' => $resourceClass,
            'route' => $route->getName(),
        ]);
    }
}
