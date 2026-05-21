<?php

namespace Mercurio\Tables\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Api\Mutate\CellMutateResult;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\Http\Controllers\JsonApiMutateController;
use Mercurio\Tables\ListResource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::cellEdit()} (HTML) +
 * {@see JsonApiMutateController} (JSON API).
 */
class CellUpdateHandler
{
    public function __construct(private ActionAuthorizer $authorizer) {}

    public function handle(Request $request, ListResource $resource, int $id, string $field): Response
    {
        $resourceClass = $resource::class;

        try {
            $fresh = $this->applyMutationCore($request, $resource, $id, $field);
        } catch (ApiValidationException $e) {
            if ($e->errorCode === ApiErrorCode::RecordNotFound) {
                abort(404);
            }
            if ($e->errorCode === ApiErrorCode::PolicyDenied) {
                abort(403);
            }

            $details = $e->details;
            $body = [];
            if (isset($details['errors']) && is_array($details['errors'])) {
                $body['errors'] = $details['errors'];
            } else {
                $body['message'] = $e->getMessage();
            }

            return response()->json($body, $e->errorCode->httpStatus());
        }

        $table = $resource->table($request);

        return response()
            ->view('tables::row-fragment', [
                'table' => $table,
                'row' => $fresh,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Pure-mutate primitive: применяет cell-edit и возвращает {@see CellMutateResult}
     * с сериализованным freshRow для JSON-envelope'а.
     *
     * Бросает {@see ApiValidationException} с конкретным {@see ApiErrorCode}.
     * HTML-стек ({@see self::handle()}) ловит и транслирует в HTTP-response.
     *
     * @param  int|string  $id
     */
    public function applyAsArray(Request $request, ListResource $resource, $id, string $field): CellMutateResult
    {
        $fresh = $this->applyMutationCore($request, $resource, $id, $field);

        return new CellMutateResult($id, $this->serializeRow($fresh), null);
    }

    /**
     * Общая для HTML- и JSON-стеков мутация. Возвращает raw $fresh
     * (Model для EloquentSource, array для тестовых Source'ов).
     *
     * @param  int|string  $id
     */
    private function applyMutationCore(Request $request, ListResource $resource, $id, string $field): mixed
    {
        $resourceClass = $resource::class;

        $f = $resource->findField($field);
        if ($f === null || ! $f->isEditable()) {
            Log::warning('tables.cell.not_editable', [
                'resource' => $resourceClass,
                'field' => $field,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::CapabilityUnsupported,
                'Поле недоступно для inline-редактирования.',
                ['field' => $field, 'reason' => 'field_not_editable'],
            );
        }

        $source = $resource->resolveSource();
        if (! $source->capabilities()->mutate) {
            Log::warning('tables.cell.update.mutate_denied', [
                'resource' => $resourceClass,
                'field' => $field,
                'id' => $id,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::CapabilityUnsupported,
                'Источник данных не поддерживает inline-редактирование.',
                ['capability' => 'mutate'],
            );
        }

        $model = $source->find($id);
        if ($model === null) {
            Log::warning('tables.cell.update.missing', [
                'resource' => $resourceClass,
                'field' => $field,
                'id' => $id,
            ]);

            throw new ApiValidationException(
                ApiErrorCode::RecordNotFound,
                'Запись не найдена.',
                ['id' => $id],
            );
        }

        $policy = $f->getEditPolicy();
        if ($policy !== null) {
            $actor = $this->authorizer->currentTableActor($resource);
            $allowed = (bool) Gate::forUser($actor)->check($policy['method'], $model);
            if (! $allowed) {
                Log::warning('tables.cell.update.forbidden', [
                    'resource' => $resourceClass,
                    'field' => $field,
                    'id' => $id,
                    'policy_class' => $policy['class'],
                    'policy_method' => $policy['method'],
                    'actor_id' => $actor?->getAuthIdentifier(),
                ]);

                throw new ApiValidationException(
                    ApiErrorCode::PolicyDenied,
                    'Редактирование запрещено политикой.',
                    ['field' => $field, 'policy_method' => $policy['method']],
                );
            }
        }

        $rules = $f->compileEditRules($model);
        $validator = Validator::make(
            ['value' => $request->input('value')],
            ['value' => $rules],
        );

        if ($validator->fails()) {
            Log::warning('tables.cell.update.validation_failed', [
                'resource' => $resourceClass,
                'field' => $field,
                'id' => $id,
                'errors' => array_keys($validator->errors()->toArray()),
            ]);

            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                'Валидация значения провалена.',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        $value = $validator->validated()['value'] ?? null;
        $column = $f->getEditableColumn();

        $transform = $f->getCellEditSpec()?->transform;
        if ($transform !== null) {
            try {
                $value = $transform($value, $model);
            } catch (Throwable $e) {
                Log::error('tables.cell_edit.transform_failed', [
                    'resource' => $resourceClass,
                    'field' => $field,
                    'id' => $id,
                    'exception' => $e::class,
                ]);

                throw new ApiValidationException(
                    ApiErrorCode::MutationFailed,
                    (string) trans('tables::cell_edit.transform_failed'),
                    ['exception' => $e::class],
                );
            }
        }

        // Source::update сам оборачивает в DB::transaction (для EloquentSource).
        // Laravel поддерживает вложенные транзакции через savepoint'ы — если
        // вызывающий код уже открыл транзакцию, это безопасно.
        $fresh = $source->update($id, [$column => $value]);

        return $fresh;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeRow(mixed $fresh): ?array
    {
        if ($fresh === null) {
            return null;
        }
        if ($fresh instanceof Model) {
            return $fresh->toArray();
        }
        if (is_array($fresh)) {
            return $fresh;
        }
        if (is_object($fresh)) {
            return (array) $fresh;
        }

        return null;
    }
}
