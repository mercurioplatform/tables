<?php

namespace Mercurio\Tables\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\ListResource;

/**
 * Парсер body эндпоинта `POST /{uri}/mutate` → {@see ParsedMutate}.
 *
 * Контракт body — JSON (`Content-Type: application/json`); любой другой
 * content-type → 400 MALFORMED_QUERY с `reason='unsupported_media_type'`.
 * Это симметрично list-парсеру ({@see ApiQueryParser::resolveSource()}) и
 * не оставляет места form-urlencoded-fallback'у.
 *
 * Discriminator — `op` поле в корне body: `"cell"|"row"|"bulk"`. Per-op шаблон body:
 *
 * - `cell`: `{op:"cell", id:..., field:"...", value:...}`
 * - `row`:  `{op:"row", id:..., action:"...", payload?:{...}}`
 * - `bulk`: `{op:"bulk", action:"...", ids:[...], payload?:{...}}`
 *
 * Все validation-fails → {@see ApiValidationException} с {@see ApiErrorCode::ValidationFailed}
 * (HTTP 422). Никакого `InvalidArgument` — следуем precedent'у JSON API.
 *
 * Whitelist полей для cell-op: `$config->getAllowFields()` к моменту вызова
 * гарантированно НЕ null — {@see ListResource::resolveApiConfig()} резолвит
 * sentinel через `fieldsMemo()`. Если null прорвался — это контракт-bug
 * вызывающего кода, парсер валит `LogicException`, а не silent-fallback.
 *
 * `?include=...` парсится из query-string; список известных mutate-include'ов
 * — {@see self::KNOWN_MUTATE_INCLUDES}. Неизвестные include'ы — warning,
 * но не fail (тот же подход, что list-side {@see ApiQueryParser}).
 *
 * Body-size guard: `payload` (`row.payload` / `bulk.payload`) — после
 * `is_array`-проверки serialized-size через `strlen(json_encode($payload))`
 * сравнивается с {@see ApiConfig::getMaxPayloadBytes()}; превышение →
 * `ApiValidationException` с `reason='payload_too_large'`.
 *
 * Единая точка логирования API-ошибок — {@see ApiErrorResponse::make()}
 * (вызывается выше по стеку контроллером); парсер не дублирует логи перед
 * throw'ом.
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
            $this->fail('missing_op', "Field 'op' is required.", ['allowed' => self::ALLOWED_OPS]);
        }
        if (! in_array($op, self::ALLOWED_OPS, true)) {
            $this->fail('unknown_op', "Unknown op: '{$op}'.", ['op' => $op, 'allowed' => self::ALLOWED_OPS]);
        }

        /** @var 'cell'|'row'|'bulk' $op */
        return match ($op) {
            'cell' => $this->parseCell($body, $config, $includes),
            'row' => $this->parseRow($body, $config, $includes),
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
            $this->fail('missing_id', "Field 'id' is required (int|string) for op=cell.", ['op' => 'cell']);
        }

        $field = $body['field'] ?? null;
        if (! is_string($field) || $field === '') {
            $this->fail('missing_field', "Field 'field' is required for op=cell.", ['op' => 'cell']);
        }

        $allowFields = $config->getAllowFields();
        if ($allowFields === null) {
            // Инвариант: ListResource::resolveApiConfig() резолвит sentinel через
            // fieldsMemo(); парсер не должен получить null. Если получили — это
            // контракт-bug вызывающего кода, валим LogicException вместо 422.
            throw new LogicException(
                'ApiConfig::allowFields is unresolved at MutateBodyParser. '
                .'ListResource::resolveApiConfig() must fill the sentinel from fieldsMemo() before parsing.',
            );
        }
        if (! in_array($field, $allowFields, true)) {
            $this->fail(
                'field_not_allowed',
                "Field '{$field}' is not allowed by API config.",
                ['op' => 'cell', 'field' => $field, 'allowed' => array_values($allowFields)],
            );
        }

        if (! array_key_exists('value', $body)) {
            $this->fail('missing_value', "Field 'value' is required for op=cell (may be null).", ['op' => 'cell']);
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
    private function parseRow(array $body, ApiConfig $config, array $includes): ParsedMutate
    {
        $id = $body['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            $this->fail('missing_id', "Field 'id' is required (int|string) for op=row.", ['op' => 'row']);
        }

        $action = $body['action'] ?? null;
        if (! is_string($action) || $action === '') {
            $this->fail('missing_action', "Field 'action' is required for op=row.", ['op' => 'row']);
        }

        $payload = $this->parsePayload($body, 'row', $config);

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
            $this->fail('missing_action', "Field 'action' is required for op=bulk.", ['op' => 'bulk']);
        }

        $ids = $body['ids'] ?? null;
        if (! is_array($ids)) {
            $this->fail('missing_ids', "Field 'ids' is required (non-empty array) for op=bulk.", ['op' => 'bulk']);
        }
        if ($ids === []) {
            $this->fail('empty_ids', "Field 'ids' must not be empty for op=bulk.", ['op' => 'bulk']);
        }

        $normalizedIds = [];
        foreach ($ids as $value) {
            if (! is_int($value) && ! is_string($value)) {
                $this->fail(
                    'invalid_ids',
                    'Every id must be int or string.',
                    ['op' => 'bulk', 'received_type' => get_debug_type($value)],
                );
            }
            $normalizedIds[] = $value;
        }

        $max = $config->getMaxBulkIds();
        if (count($normalizedIds) > $max) {
            $this->fail(
                'too_many_ids',
                "Too many ids: max {$max}.",
                ['op' => 'bulk', 'max' => $max, 'given' => count($normalizedIds)],
            );
        }

        $payload = $this->parsePayload($body, 'bulk', $config);

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
    private function parsePayload(array $body, string $op, ApiConfig $config): array
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
                "Field 'payload' must be an object (assoc array) or null.",
                ['op' => $op, 'received_type' => get_debug_type($payload)],
            );
        }

        $max = $config->getMaxPayloadBytes();
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $size = $encoded === false ? PHP_INT_MAX : strlen($encoded);
        if ($size > $max) {
            $this->fail(
                'payload_too_large',
                "Payload exceeds {$max} bytes (got {$size}).",
                ['op' => $op, 'max' => $max, 'given' => $size],
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractBody(Request $request): array
    {
        if (! $request->isJson()) {
            throw new ApiValidationException(
                ApiErrorCode::MalformedQuery,
                "Mutate body must use 'application/json' Content-Type.",
                [
                    'reason' => 'unsupported_media_type',
                    'received' => $request->header('Content-Type'),
                ],
            );
        }

        $json = $request->json()->all();

        return is_array($json) ? $json : [];
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
        throw new ApiValidationException(
            ApiErrorCode::ValidationFailed,
            $message,
            ['reason' => $reason] + $details,
        );
    }
}
