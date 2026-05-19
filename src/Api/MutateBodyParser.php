<?php

namespace Mercurio\Tables\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\ListResource;

/**
 * Парсер body эндпоинта `POST /{uri}/mutate` → {@see ParsedMutate}.
 *
 * Контракт body — JSON (application/json) либо form-urlencoded (через
 * `$request->all()` fallback'ом). Discriminator — `op` поле в корне body:
 * `"cell"|"row"|"bulk"`. Per-op шаблон body:
 *
 * - `cell`: `{op:"cell", id:..., field:"...", value:...}`
 * - `row`:  `{op:"row", id:..., action:"...", payload?:{...}}`
 * - `bulk`: `{op:"bulk", action:"...", ids:[...], payload?:{...}}`
 *
 * Все validation-fails → {@see ApiValidationException} с {@see ApiErrorCode::ValidationFailed}
 * (HTTP 422). Никакого `InvalidArgument` — следуем precedent'у JSON API.
 *
 * Whitelist полей для cell-op:
 * `$config->getAllowFields()` к моменту вызова гарантированно НЕ null —
 * {@see ListResource::resolveApiConfig()} резолвит sentinel через
 * `fieldsMemo()`. Никакого fallback'а в парсере.
 *
 * `?include=...` парсится из query-string; список известных mutate-include'ов
 * — {@see self::KNOWN_MUTATE_INCLUDES}. Неизвестные include'ы — warning,
 * но не fail (тот же подход, что list-side {@see ApiQueryParser}).
 */
final class MutateBodyParser
{
    public const KNOWN_MUTATE_INCLUDES = ['undoToken'];

    private const ALLOWED_OPS = ['cell', 'row', 'bulk'];

    public function parse(Request $request, ApiConfig $config, ListResource $resource): ParsedMutate
    {
        $body = $this->extractBody($request);
        $includes = $this->parseIncludes($request);

        $op = $body['op'] ?? null;
        if (! is_string($op) || $op === '') {
            $this->fail('missing_op', 'Поле op обязательно.', ['allowed' => self::ALLOWED_OPS]);
        }
        if (! in_array($op, self::ALLOWED_OPS, true)) {
            $this->fail('unknown_op', "Неизвестная операция: {$op}.", ['op' => $op, 'allowed' => self::ALLOWED_OPS]);
        }

        /** @var 'cell'|'row'|'bulk' $op */
        return match ($op) {
            'cell' => $this->parseCell($body, $config, $includes),
            'row' => $this->parseRow($body, $includes),
            'bulk' => $this->parseBulk($body, $config, $includes),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $includes
     */
    private function parseCell(array $body, ApiConfig $config, array $includes): ParsedMutate
    {
        $id = $body['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            $this->fail('missing_id', 'Поле id обязательно (int|string) для op=cell.', ['op' => 'cell']);
        }

        $field = $body['field'] ?? null;
        if (! is_string($field) || $field === '') {
            $this->fail('missing_field', 'Поле field обязательно для op=cell.', ['op' => 'cell']);
        }

        $allowFields = $config->getAllowFields();
        if ($allowFields === null) {
            // Инвариант: ListResource::resolveApiConfig() резолвит sentinel
            // через fieldsMemo(); парсер не должен получить null. Если получили —
            // это баг вызывающего кода, явно валим.
            $this->fail(
                'allow_fields_not_resolved',
                'ApiConfig.allowFields не зарезолвлен — это баг ListResource::resolveApiConfig().',
                ['op' => 'cell', 'field' => $field],
            );
        }
        if (! in_array($field, $allowFields, true)) {
            $this->fail(
                'field_not_allowed',
                "Поле '{$field}' не разрешено API config'ом.",
                ['op' => 'cell', 'field' => $field, 'allowed' => array_values($allowFields)],
            );
        }

        if (! array_key_exists('value', $body)) {
            $this->fail('missing_value', 'Поле value обязательно для op=cell (может быть null).', ['op' => 'cell']);
        }
        $value = $body['value'];

        return new ParsedMutate(
            op: 'cell',
            id: $id,
            field: $field,
            value: $value,
            includes: $includes,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $includes
     */
    private function parseRow(array $body, array $includes): ParsedMutate
    {
        $id = $body['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            $this->fail('missing_id', 'Поле id обязательно (int|string) для op=row.', ['op' => 'row']);
        }

        $action = $body['action'] ?? null;
        if (! is_string($action) || $action === '') {
            $this->fail('missing_action', 'Поле action обязательно для op=row.', ['op' => 'row']);
        }

        $payload = $this->parsePayload($body, 'row');

        return new ParsedMutate(
            op: 'row',
            id: $id,
            action: $action,
            payload: $payload,
            includes: $includes,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $includes
     */
    private function parseBulk(array $body, ApiConfig $config, array $includes): ParsedMutate
    {
        $action = $body['action'] ?? null;
        if (! is_string($action) || $action === '') {
            $this->fail('missing_action', 'Поле action обязательно для op=bulk.', ['op' => 'bulk']);
        }

        $ids = $body['ids'] ?? null;
        if (! is_array($ids)) {
            $this->fail('missing_ids', 'Поле ids обязательно (non-empty array) для op=bulk.', ['op' => 'bulk']);
        }
        if ($ids === []) {
            $this->fail('empty_ids', 'Поле ids не должно быть пустым для op=bulk.', ['op' => 'bulk']);
        }

        $normalizedIds = [];
        foreach ($ids as $value) {
            if (! is_int($value) && ! is_string($value)) {
                $this->fail(
                    'invalid_ids',
                    'Каждый id должен быть int или string.',
                    ['op' => 'bulk', 'received_type' => get_debug_type($value)],
                );
            }
            $normalizedIds[] = $value;
        }

        $max = $config->getMaxBulkIds();
        if (count($normalizedIds) > $max) {
            $this->fail(
                'too_many_ids',
                "Превышен лимит ids: {$max}.",
                ['op' => 'bulk', 'max' => $max, 'given' => count($normalizedIds)],
            );
        }

        $payload = $this->parsePayload($body, 'bulk');

        return new ParsedMutate(
            op: 'bulk',
            action: $action,
            ids: $normalizedIds,
            payload: $payload,
            includes: $includes,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function parsePayload(array $body, string $op): array
    {
        if (! array_key_exists('payload', $body)) {
            return [];
        }
        $payload = $body['payload'];
        if ($payload === null) {
            return [];
        }
        if (! is_array($payload)) {
            $this->fail(
                'invalid_payload',
                'Поле payload должно быть объектом (assoc array) или null.',
                ['op' => $op, 'received_type' => get_debug_type($payload)],
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractBody(Request $request): array
    {
        if ($request->isJson()) {
            $json = $request->json()->all();

            return is_array($json) ? $json : [];
        }

        return (array) $request->all();
    }

    /**
     * @return array<int, string>
     */
    private function parseIncludes(Request $request): array
    {
        $raw = $request->query('include');
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $requested = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        ));

        $known = [];
        foreach ($requested as $block) {
            if (! in_array($block, self::KNOWN_MUTATE_INCLUDES, true)) {
                Log::warning('tables.api.mutate.unknown_include', [
                    'include' => $block,
                    'allowed' => self::KNOWN_MUTATE_INCLUDES,
                ]);

                continue;
            }
            $known[] = $block;
        }

        return array_values(array_unique($known));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return never
     */
    private function fail(string $reason, string $message, array $details = []): void
    {
        Log::warning('tables.api.mutate.invalid_body', [
            'reason' => $reason,
            'details' => $details,
        ]);

        throw new ApiValidationException(
            ApiErrorCode::ValidationFailed,
            $message,
            ['reason' => $reason] + $details,
        );
    }
}
