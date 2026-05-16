<?php

namespace Mercurio\Tables\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Mercurio\Tables\Action\Helpers\ActionAuthorizer;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\ListResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::cellEdit()}.
 */
class CellUpdateHandler
{
    public function __construct(private ActionAuthorizer $authorizer) {}

    public function handle(Request $request, ListResource $resource, $id, string $field): Response
    {
        $resourceClass = $resource::class;
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        $f = $resource->findField($field);
        if ($f === null || ! $f->isEditable()) {
            Log::warning('tables.cell.not_editable', [
                'resource' => $resourceClass,
                'field' => $field,
            ]);

            return response()->json([
                'message' => 'Поле недоступно для inline-редактирования.',
            ], 422);
        }

        $source = $resource->resolveSource();
        if (! $source->capabilities()->mutate) {
            Log::warning('tables.cell.update.mutate_denied', [
                'resource' => $resourceClass,
                'field' => $field,
                'id' => $id,
            ]);

            return response()->json([
                'message' => 'Источник данных не поддерживает inline-редактирование.',
            ], 422);
        }

        $model = $source->find($id);
        if ($model === null) {
            Log::warning('tables.cell.update.missing', [
                'resource' => $resourceClass,
                'field' => $field,
                'id' => $id,
            ]);
            abort(404);
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
                abort(403);
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

            return response()->json([
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $value = $validator->validated()['value'] ?? null;
        $column = $f->getEditableColumn();

        $transform = $f->getCellEditSpec()?->transform;
        if ($transform !== null) {
            try {
                $value = $transform($value, $model);
            } catch (\Throwable $e) {
                Log::error('tables.cell_edit.transform_failed', [
                    'resource' => $resourceClass,
                    'field' => $field,
                    'id' => $id,
                    'exception' => $e::class,
                ]);

                return response()->json([
                    'message' => (string) trans('tables::cell_edit.transform_failed'),
                ], 422);
            }
        }

        // Source::update сам оборачивает в DB::transaction (для EloquentSource).
        // Laravel поддерживает вложенные транзакции через savepoint'ы — если
        // вызывающий код уже открыл транзакцию, это безопасно.
        $fresh = $source->update($id, [$column => $value]);
        $table = $resource->table($request);

        return response()
            ->view('tables::row-fragment', [
                'table' => $table,
                'row' => $fresh,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
