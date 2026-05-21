<?php

namespace Mercurio\Tables\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Concerns\HandlesResourceListing;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see Route::tablesPage()}.
 */
final class GenericTablesController
{
    use HandlesResourceListing;

    /** @var class-string */
    protected string $resource;

    protected ?string $routeBaseName = null;

    protected ?string $tableView = null;

    /** @var array<string, string> */
    protected array $bulkActionForms = [];

    /** @var class-string|null */
    protected ?string $bulkRequest = null;

    /** @var array<string, string> */
    protected array $rowActionForms = [];

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
    }
}
