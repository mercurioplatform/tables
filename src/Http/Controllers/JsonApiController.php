<?php

namespace Mercurio\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\ApiErrorResponse;
use Mercurio\Tables\Api\ApiQueryParser;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\JsonRenderer;
use Mercurio\Tables\Api\ParsedApiQuery;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;
use Throwable;

/**
 * Single-action controller для JSON API-эндпоинта `Route::tablesApi(...)`.
 *
 * Pipeline:
 *  1. Резолв класса ресурса через `route()->defaults['resource']`;
 *  2. {@see ListResource::resolveApiConfig()};
 *  3. {@see ApiQueryParser::parse()} (catch → 422);
 *  4. {@see ListResource::resolveSource()}->withQuery($parsed->query);
 *  5. {@see self::assertCapabilities()} (capabilities-gating: filter/sort/search/qbTree);
 *  6. {@see Source::page()};
 *  7. {@see JsonRenderer::render()};
 *  8. JsonResponse 200.
 *
 * @internal Public surface — `Route::tablesApi('orders', OrdersResource::class)`.
 */
final class JsonApiController
{
    public function __construct(
        private readonly ApiQueryParser $parser,
        private readonly JsonRenderer $renderer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $route = $request->route();
        $resourceClass = $route?->defaults['resource'] ?? null;

        if (! is_string($resourceClass) || ! class_exists($resourceClass)) {
            Log::error('tables.api.bad_route', [
                'resource_default' => $resourceClass,
                'path' => $request->path(),
            ]);

            throw new LogicException(
                'JsonApiController: route is missing "resource" default — register the route via Route::tablesApi().',
            );
        }

        if (! is_subclass_of($resourceClass, ListResource::class)) {
            Log::error('tables.api.bad_resource_class', [
                'resource' => $resourceClass,
                'path' => $request->path(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::ResourceNotFound,
                "Resource '{$resourceClass}' is not a ListResource.",
                ['resource' => $resourceClass],
            );
        }

        $resource = app($resourceClass);
        if (! $resource instanceof ListResource) {
            return ApiErrorResponse::make(
                ApiErrorCode::ResourceNotFound,
                "Resolved instance of '{$resourceClass}' is not a ListResource.",
                ['resource' => $resourceClass],
            );
        }

        $config = $resource->resolveApiConfig();

        try {
            $parsed = $this->parser->parse($request, $config, $resource);
        } catch (ApiValidationException $e) {
            return ApiErrorResponse::fromException($e);
        }

        try {
            $source = $resource->resolveSource()->withQuery($parsed->query);
        } catch (Throwable $e) {
            Log::error('tables.api.source_error', [
                'resource' => $resource->key(),
                'stage' => 'resolve_source',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::SourceError,
                'Source failed while applying query.',
                ['exception' => $e::class],
            );
        }

        try {
            $this->assertCapabilities($source, $parsed);
        } catch (ApiValidationException $e) {
            return ApiErrorResponse::fromException($e);
        }

        try {
            $page = $source->page($parsed->page, $parsed->perPage);
        } catch (Throwable $e) {
            Log::error('tables.api.source_error', [
                'resource' => $resource->key(),
                'stage' => 'page',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::SourceError,
                'Source failed while loading page.',
                ['exception' => $e::class],
            );
        }

        $envelope = $this->renderer->render($page, $source, $resource, $parsed, $config);

        return new JsonResponse($envelope, 200);
    }

    /**
     * Capabilities-gating. Бросает {@see ApiValidationException} с кодом
     * {@see ApiErrorCode::CapabilityUnsupported}.
     */
    private function assertCapabilities(Source $source, ParsedApiQuery $parsed): void
    {
        $caps = $source->capabilities();

        if ($parsed->query->qbRoot !== null && ! $caps->qbTree) {
            $this->throwCapability('qbTree', 'Source does not support QB-tree queries (qbTree capability).');
        }

        if ($parsed->query->conditions !== [] && ! $caps->filter) {
            $this->throwCapability('filter', 'Source does not support filtering.');
        }

        if ($parsed->query->sortField !== null && ! $caps->sort) {
            $this->throwCapability('sort', 'Source does not support sorting.');
        }

        if ($parsed->query->search !== null && $parsed->query->search !== '' && ! $caps->search) {
            $this->throwCapability('search', 'Source does not support search.');
        }

        if ($parsed->query->savedViewKey !== null && ! $caps->filter) {
            $this->throwCapability('filter', 'Saved views require filter capability.');
        }
    }

    private function throwCapability(string $capability, string $message): never
    {
        Log::warning('tables.api.capability_denied', [
            'capability' => $capability,
            'message' => $message,
        ]);

        throw new ApiValidationException(
            ApiErrorCode::CapabilityUnsupported,
            $message,
            ['capability' => $capability],
        );
    }
}
