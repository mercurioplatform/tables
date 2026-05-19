<?php

namespace Mercurio\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mercurio\Tables\Action\Handlers\BulkActionHandler;
use Mercurio\Tables\Action\Handlers\RowActionHandler;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\ApiErrorResponse;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\Mutate\BulkMutateResult;
use Mercurio\Tables\Api\Mutate\MutateResult;
use Mercurio\Tables\Api\MutateBodyParser;
use Mercurio\Tables\Api\MutateRenderer;
use Mercurio\Tables\Api\ParsedMutate;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Services\CellUpdateHandler;
use Throwable;

/**
 * Single-action controller для mutate-эндпоинта `POST /{uri}/mutate`.
 *
 * Pipeline:
 *  1.  Резолв класса ресурса через `route()->defaults['resource']`;
 *  2.  {@see ListResource::resolveApiConfig()};
 *  3.  Hard-gate: `! $config->getAllowMutations()` → 403 `MutationsDisabled`;
 *  3b. Coarse Gate: если `$config->getMutateAbility() !== null` →
 *      `Gate::check(ability, $resource)`; при false → 403 `PolicyDenied`.
 *  4.  {@see MutateBodyParser::parse()} (catch → 422);
 *  5.  Source.capabilities.mutate check → 422 `CapabilityUnsupported`;
 *  6.  Dispatch по op-дискриминатору к pure-методу хэндлера;
 *  7.  {@see MutateRenderer::render()} → JSON-envelope;
 *  8.  Status 202 (queued bulk) или 200 (sync).
 *
 * @internal Public surface — `Route::tablesApi('orders', OrdersResource::class)`
 *           + `ApiConfig::allowMutations(true)` на ресурсе.
 */
final class JsonApiMutateController
{
    public function __construct(
        private readonly MutateBodyParser $parser,
        private readonly CellUpdateHandler $cellHandler,
        private readonly RowActionHandler $rowHandler,
        private readonly BulkActionHandler $bulkHandler,
        private readonly MutateRenderer $renderer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $route = $request->route();
        $resourceClass = $route?->defaults['resource'] ?? null;

        if (! is_string($resourceClass) || ! class_exists($resourceClass)) {
            Log::error('tables.api.mutate.bad_route', [
                'resource_default' => $resourceClass,
                'path' => $request->path(),
            ]);

            throw new LogicException(
                'JsonApiMutateController: route is missing "resource" default — register the route via Route::tablesApi().',
            );
        }

        if (! is_subclass_of($resourceClass, ListResource::class)) {
            Log::error('tables.api.mutate.bad_resource_class', [
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

        if (! $config->getAllowMutations()) {
            Log::warning('tables.api.mutate.gate_denied', [
                'resource' => $resource->key(),
                'gate' => 'allowMutations',
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::MutationsDisabled,
                'Mutate API отключён для этого ресурса (allowMutations=false).',
                ['resource' => $resource->key()],
            );
        }

        $ability = $config->getMutateAbility();
        if ($ability !== null && ! Gate::check($ability, $resource)) {
            Log::warning('tables.api.mutate.coarse_gate_denied', [
                'resource' => $resource->key(),
                'ability' => $ability,
                'actor_id' => $request->user()?->getKey(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::PolicyDenied,
                "Coarse Gate denied access to mutate API (ability='{$ability}').",
                ['ability' => $ability, 'resource' => $resource->key()],
            );
        }

        try {
            $parsed = $this->parser->parse($request, $config, $resource);
        } catch (ApiValidationException $e) {
            return ApiErrorResponse::fromException($e);
        }

        try {
            $source = $resource->resolveSource();
        } catch (Throwable $e) {
            Log::error('tables.api.mutate.source_error', [
                'resource' => $resource->key(),
                'stage' => 'resolve_source',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::SourceError,
                'Не удалось получить Source для ресурса.',
                ['exception' => $e::class],
            );
        }

        if (! $source->capabilities()->mutate) {
            Log::warning('tables.api.mutate.gate_denied', [
                'resource' => $resource->key(),
                'gate' => 'capability',
                'op' => $parsed->op,
            ]);

            return ApiErrorResponse::capabilityUnsupported('mutate');
        }

        try {
            $result = $this->dispatchOp($parsed, $resource, $request);
        } catch (ApiValidationException $e) {
            return ApiErrorResponse::fromException($e);
        } catch (ValidationException $e) {
            Log::warning('tables.api.mutate.form_validation_failed', [
                'resource' => $resource->key(),
                'op' => $parsed->op,
                'errors' => array_keys($e->errors()),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::ValidationFailed,
                $e->getMessage(),
                ['errors' => $e->errors()],
            );
        } catch (Throwable $e) {
            Log::error('tables.api.mutate.unexpected_error', [
                'resource' => $resource->key(),
                'op' => $parsed->op,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiErrorResponse::make(
                ApiErrorCode::MutationFailed,
                'Внутренняя ошибка mutate-операции.',
                ['exception' => $e::class],
            );
        }

        $envelope = $this->renderer->render($result, $parsed, $resource, $request);
        $status = $result instanceof BulkMutateResult && $result->progressId !== null ? 202 : 200;

        return new JsonResponse($envelope, $status);
    }

    private function dispatchOp(ParsedMutate $parsed, ListResource $resource, Request $request): MutateResult
    {
        return match ($parsed->op) {
            'cell' => $this->cellHandler->applyAsArray($request, $resource, $parsed->id, (string) $parsed->field),
            'row' => $this->rowHandler->applyAsResult($request, $resource, $parsed->id, (string) $parsed->action),
            'bulk' => $this->bulkHandler->applyAsResult(
                $request,
                $resource,
                (string) $parsed->action,
                $parsed->ids ?? [],
                $parsed->payload,
            ),
            default => throw new LogicException("Unknown op: {$parsed->op}"),
        };
    }
}
