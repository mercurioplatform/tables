<?php

namespace Mercurio\Tables\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

class PendingTablesResource
{
    private Route $indexRoute;

    private Route $bulkActionRoute;

    private Route $optionsRoute;

    public function __construct(Router $router, string $path, string $controller)
    {
        $normalized = ltrim($path, '/');
        $optionsSuffix = (string) config('tables.route_options_suffix', '/options');

        $this->indexRoute = $router->get($normalized, [$controller, 'index']);
        $this->bulkActionRoute = $router->post(
            rtrim($normalized, '/').'/bulk-action',
            [$controller, 'bulkAction'],
        );
        $this->optionsRoute = $router->get(
            rtrim($normalized, '/').$optionsSuffix,
            [$controller, 'options'],
        );
    }

    public function name(string $base): self
    {
        $this->indexRoute->name($base.'.index');
        $this->bulkActionRoute->name($base.'.bulk_action');
        $this->optionsRoute->name($base.'.options');

        return $this;
    }

    /** @param array<int, string>|string $middleware */
    public function middleware(array|string $middleware): self
    {
        $this->indexRoute->middleware($middleware);
        $this->bulkActionRoute->middleware($middleware);
        $this->optionsRoute->middleware($middleware);

        return $this;
    }

    /** @param array<string, string> $constraints */
    public function where(array $constraints): self
    {
        $this->indexRoute->where($constraints);
        $this->bulkActionRoute->where($constraints);
        $this->optionsRoute->where($constraints);

        return $this;
    }
}
