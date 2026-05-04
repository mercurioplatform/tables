<?php

namespace Mercurio\Tables\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

class PendingTablesResource
{
    private Route $indexRoute;

    private Route $bulkActionRoute;

    public function __construct(Router $router, string $path, string $controller)
    {
        $normalized = ltrim($path, '/');

        $this->indexRoute = $router->get($normalized, [$controller, 'index']);
        $this->bulkActionRoute = $router->post(
            rtrim($normalized, '/').'/bulk-action',
            [$controller, 'bulkAction'],
        );
    }

    public function name(string $base): self
    {
        $this->indexRoute->name($base.'.index');
        $this->bulkActionRoute->name($base.'.bulk_action');

        return $this;
    }

    /** @param array<int, string>|string $middleware */
    public function middleware(array|string $middleware): self
    {
        $this->indexRoute->middleware($middleware);
        $this->bulkActionRoute->middleware($middleware);

        return $this;
    }

    /** @param array<string, string> $constraints */
    public function where(array $constraints): self
    {
        $this->indexRoute->where($constraints);
        $this->bulkActionRoute->where($constraints);

        return $this;
    }
}
