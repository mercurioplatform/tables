<?php

namespace Mercurio\Tables\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Mercurio\Tables\Http\Controllers\JsonApiController;
use Mercurio\Tables\Http\Controllers\JsonApiMutateController;
use Mercurio\Tables\Http\Controllers\JsonApiSchemaController;

/**
 * Pending-builder для макроса `Route::tablesApi(...)`.
 *
 * По образцу {@see PendingTablesResource}: конструктор регистрирует
 * маршруты:
 * - `GET  /{uri}`        → {@see JsonApiController::class} — index/read с query-параметрами;
 * - `POST /{uri}`        → {@see JsonApiController::class} — тот же read, но через JSON body
 *                          (нужен для произвольного QB-дерева, которое не лезет в URL);
 * - `GET  /{uri}/schema` → {@see JsonApiSchemaController::class};
 * - `POST /{uri}/mutate` → {@see JsonApiMutateController::class}.
 *
 * GET и POST на индекс-URL — это **один и тот же логический ресурс** (чтение),
 * отвечающий двумя HTTP-методами, а не две поверхности. Это стандартный
 * REST-pattern: GET для shareable/cacheable URL, POST для тел без 8KB-лимита.
 * UI vs API остаются разделены файлами маршрутов через отдельные макросы
 * (`Route::tablesPage` vs `Route::tablesApi`).
 *
 * Каждому маршруту ставится `->defaults('resource', $resourceClass)`.
 * Fluent-модификаторы (`name`, `middleware`, `where`) применяются ко всем
 * зарегистрированным маршрутам.
 *
 * Регистрация всегда явная — middleware наследуется от файла маршрутов
 * (`routes/api.php` → `api` stack, `routes/web.php` → `web` stack).
 * Mutate-эндпоинт публикуется маршрутом всегда (ради единого URL-shape и
 * одинакового middleware-стека), но gate'ится на уровне контроллера через
 * `ApiConfig::allowMutations(true)`; если хост явно не включил mutations,
 * любой POST `/mutate` возвращает 403 `MutationsDisabled`.
 *
 * `name($base)` присваивает route-имена:
 * - GET  `/{uri}`        → `{$base}.index`;
 * - POST `/{uri}`        → `{$base}.query` (REST-convention для read-via-POST,
 *                          отличный от `.store=write` и слишком generic `.post`);
 * - GET  `/{uri}/schema` → `{$base}.schema`;
 * - POST `/{uri}/mutate` → `{$base}.mutate`.
 *
 * @internal Public surface — `Route::tablesApi('orders', OrdersResource::class)`.
 */
class PendingTablesApiResource
{
    private Route $indexRoute;

    private Route $queryRoute;

    private Route $schemaRoute;

    private Route $mutateRoute;

    public function __construct(Router $router, string $uri, string $resourceClass)
    {
        $normalized = ltrim($uri, '/');

        $this->indexRoute = $router->get($normalized, JsonApiController::class);
        $this->queryRoute = $router->post($normalized, JsonApiController::class);
        $this->schemaRoute = $router->get($normalized.'/schema', JsonApiSchemaController::class);
        $this->mutateRoute = $router->post($normalized.'/mutate', JsonApiMutateController::class);

        foreach ($this->routes() as $route) {
            $route->defaults('resource', $resourceClass);
        }
    }

    public function name(string $base): self
    {
        $this->indexRoute->name($base.'.index');
        $this->queryRoute->name($base.'.query');
        $this->schemaRoute->name($base.'.schema');
        $this->mutateRoute->name($base.'.mutate');

        return $this;
    }

    /** @param  array<int, string>|string  $middleware */
    public function middleware(array|string $middleware): self
    {
        foreach ($this->routes() as $route) {
            $route->middleware($middleware);
        }

        return $this;
    }

    /** @param  array<string, string>  $constraints */
    public function where(array $constraints): self
    {
        foreach ($this->routes() as $route) {
            $route->where($constraints);
        }

        return $this;
    }

    /** @return array<int, Route> */
    private function routes(): array
    {
        return [$this->indexRoute, $this->queryRoute, $this->schemaRoute, $this->mutateRoute];
    }
}
