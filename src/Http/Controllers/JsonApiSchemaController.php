<?php

namespace Mercurio\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\ApiErrorResponse;
use Mercurio\Tables\Api\SchemaBuilder;
use Mercurio\Tables\ListResource;
use Throwable;

/**
 * Single-action controller для discovery-endpoint'а `GET /{uri}/schema`.
 *
 * Pipeline:
 *  1. Резолв класса ресурса через `route()->defaults['resource']`;
 *  2. {@see ListResource::resolveApiConfig()};
 *  3. {@see SchemaBuilder::build()} (без переданного Source — SchemaBuilder
 *     сам зовёт `resolveSource()` ради `capabilities()`);
 *  4. JsonResponse `{schema: {...}}` 200.
 *
 * @internal Public surface — `Route::tablesApi('orders', OrdersResource::class)`.
 */
final class JsonApiSchemaController
{
    public function __construct(
        private readonly SchemaBuilder $schema,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $route = $request->route();
        $resourceClass = $route?->defaults['resource'] ?? null;

        if (! is_string($resourceClass) || ! class_exists($resourceClass)) {
            Log::error('tables.api.schema_bad_route', [
                'resource_default' => $resourceClass,
                'path' => $request->path(),
            ]);

            throw new LogicException(
                'JsonApiSchemaController: route is missing "resource" default — register the route via Route::tablesApi().',
            );
        }

        if (! is_subclass_of($resourceClass, ListResource::class)) {
            Log::error('tables.api.schema_bad_resource_class', [
                'resource' => $resourceClass,
                'path' => $request->path(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::ResourceNotFound,
                "Resource '{$resourceClass}' is not a ListResource.",
                ['resource' => $resourceClass],
            );
        }

        try {
            $resource = app($resourceClass);
            if (! $resource instanceof ListResource) {
                return ApiErrorResponse::make(
                    ApiErrorCode::ResourceNotFound,
                    "Resolved instance of '{$resourceClass}' is not a ListResource.",
                    ['resource' => $resourceClass],
                );
            }

            $config = $resource->resolveApiConfig();
            $schema = $this->schema->build($resource, $config);
        } catch (Throwable $e) {
            Log::error('tables.api.schema_error', [
                'resource' => $resourceClass,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::SourceError,
                'Failed to build schema for resource.',
                ['exception' => $e::class],
            );
        }

        return new JsonResponse(['schema' => $schema], 200);
    }
}
