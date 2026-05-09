<?php

namespace Mercurio\Tables\Action\Helpers;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Form\Field\FieldRow;
use Mercurio\Tables\Form\Field\FormField;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see \Mercurio\Tables\Concerns\HandlesResourceListing}.
 */
class ActionPayloadResolver
{
    /**
     * @return array{payload: array<string, mixed>, source: string}
     */
    public function resolveSchemaPayload(BulkAction|RowAction $action, Request $request, string $resourceClass): array
    {
        if (! $action->hasSchema()) {
            return ['payload' => [], 'source' => 'none'];
        }

        $fields = $this->flattenSchemaFields($action->getSchema());
        if ($fields === []) {
            return ['payload' => [], 'source' => 'none'];
        }

        $rules = [];
        $messages = [];
        $attributes = [];
        $hasAnyRules = false;

        foreach ($fields as $field) {
            $compiled = $field->compileRules();
            if ($compiled === []) {
                continue;
            }
            $hasAnyRules = true;
            $rules[$field->name] = $compiled;
            foreach ($field->getMessages() as $key => $msg) {
                $messages[$field->name.'.'.$key] = $msg;
            }
            $attributes[$field->name] = $field->getAttribute() ?? $field->label;
        }

        $prepareHook = $action->getPrepareInputHook();
        $withValidatorHook = $action->getWithValidatorHook();
        $transformHook = $action->getTransformValidatedHook();

        if (! $hasAnyRules && $prepareHook === null && $withValidatorHook === null && $transformHook === null) {
            return [
                'payload' => $this->collectSchemaInput($fields, $request),
                'source' => 'none',
            ];
        }

        $input = $request->all();
        if ($prepareHook !== null) {
            $input = $prepareHook($input);
        }

        $validator = Validator::make($input, $rules, $messages, $attributes);
        if ($withValidatorHook !== null) {
            $withValidatorHook($validator, $input);
        }

        try {
            $validated = $validator->validate();
        } catch (ValidationException $e) {
            Log::warning('tables.form.validation_failed', [
                'resource' => $resourceClass,
                'action' => $action->name,
                'errors' => array_keys($e->errors()),
            ]);
            throw $e;
        }

        $schemaKeys = array_map(fn (FormField $f) => $f->name, $fields);
        $validated = array_intersect_key($validated, array_flip($schemaKeys));

        if ($transformHook !== null) {
            $validated = $transformHook($validated);
        }

        return ['payload' => $validated, 'source' => 'schema'];
    }

    /**
     * @param  array<int, FormField|FieldRow>  $schema
     * @return array<int, FormField>
     */
    public function flattenSchemaFields(array $schema): array
    {
        $out = [];
        foreach ($schema as $entry) {
            if ($entry instanceof FieldRow) {
                foreach ($entry->fields as $f) {
                    $out[] = $f;
                }
            } elseif ($entry instanceof FormField) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, FormField>  $fields
     * @return array<string, mixed>
     */
    public function collectSchemaInput(array $fields, Request $request): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($request->has($f->name)) {
                $out[$f->name] = $request->input($f->name);
            }
        }

        return $out;
    }

    /**
     * @return array{payload: array<string, mixed>, source: string}
     */
    public function resolveRowActionPayload(Request $request, RowAction $action, string $resourceClass): array
    {
        if ($action->getKind() !== 'form') {
            return ['payload' => [], 'source' => 'none'];
        }

        $formRequestClass = $action->getFormRequest();
        if ($formRequestClass !== null) {
            /** @var FormRequest $formRequest */
            $formRequest = app($formRequestClass);

            return ['payload' => $formRequest->validated(), 'source' => 'form_request'];
        }

        return $this->resolveSchemaPayload($action, $request, $resourceClass);
    }

    /**
     * @return array<int, mixed>
     */
    public function extractBulkIds(Request $request): array
    {
        $raw = $request->input('ids', []);

        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), fn ($v) => $v !== '');
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique($raw));
    }

    public function renderActionPreview(View|string|array $result): string
    {
        if ($result instanceof View) {
            return $result->render();
        }

        if (is_string($result)) {
            return $result;
        }

        return view('tables::confirm-preview-default', ['data' => $result])->render();
    }

    public function actionPreviewReturnType(mixed $result): string
    {
        return match (true) {
            $result instanceof View => 'view',
            is_string($result) => 'string',
            is_array($result) => 'array',
            default => 'unknown',
        };
    }

    public function buildExportFilename(string $resourceKey): string
    {
        $prefix = (string) config('tables.export.filename_prefix', '');
        $slug = Str::slug(str_replace('.', '-', $resourceKey));
        $stamp = now()->format('Ymd-Hi');

        return ltrim($prefix.$slug.'-'.$stamp.'.csv', '-');
    }
}
