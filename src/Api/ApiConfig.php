<?php

namespace Mercurio\Tables\Api;

use Mercurio\Tables\ListResource;

/**
 * Конфиг JSON API-поверхности на уровне ресурса.
 *
 * Immutable VO с fluent withers. Создаётся через {@see self::make()} и
 * настраивается цепочкой `->allowFields(...)`, `->defaultFormat(...)`, и т.д.
 *
 * Используется:
 * - {@see ListResource::api()} — host-override на ресурсе;
 * - {@see ApiQueryParser} — для whitelist'а полей/savedViews и default'ов;
 * - {@see JsonRenderer} — для default `?include` блоков и `defaultFormat`.
 *
 * Sentinel'ы `null` для `allowFields`/`allowSavedViews` означают «резолвить
 * автоматически из `fieldsMemo()` / `savedViewsMemo()` в `resolveApiConfig()`».
 * Это позволяет ресурсу не дублировать списки, если они совпадают с
 * декларацией fields/savedViews.
 */
final class ApiConfig
{
    /**
     * @param  array<int, string>|null  $allowFields  null = резолвить из fieldsMemo()
     * @param  array<int, string>|null  $allowSavedViews  null = резолвить из savedViewsMemo()
     * @param  array<int, string>  $defaultIncludes  блоки envelope'а по умолчанию
     */
    private function __construct(
        public readonly ?array $allowFields,
        public readonly ?array $allowSavedViews,
        public readonly bool $allowMutations,
        public readonly FormatMode $defaultFormat,
        public readonly array $defaultIncludes,
        public readonly int $defaultPerPage,
        public readonly int $maxPerPage,
        public readonly ?string $rateLimit,
        public readonly int $maxBulkIds,
        public readonly ?string $mutateAbility,
        public readonly int $maxPayloadBytes,
    ) {}

    public static function make(): self
    {
        return new self(
            allowFields: null,
            allowSavedViews: null,
            allowMutations: false,
            defaultFormat: FormatMode::Raw,
            defaultIncludes: ['data', 'page'],
            defaultPerPage: 25,
            maxPerPage: 200,
            rateLimit: null,
            maxBulkIds: 1000,
            mutateAbility: null,
            maxPayloadBytes: 65536,
        );
    }

    /**
     * @param  array<int, string>  $names
     */
    public function allowFields(array $names): self
    {
        return $this->with(allowFields: array_values($names));
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function allowSavedViews(array $keys): self
    {
        return $this->with(allowSavedViews: array_values($keys));
    }

    public function allowMutations(bool $value = true): self
    {
        return $this->with(allowMutations: $value);
    }

    public function defaultFormat(FormatMode $mode): self
    {
        return $this->with(defaultFormat: $mode);
    }

    /**
     * @param  array<int, string>  $includes
     */
    public function defaultIncludes(array $includes): self
    {
        return $this->with(defaultIncludes: array_values($includes));
    }

    public function defaultPerPage(int $value): self
    {
        return $this->with(defaultPerPage: max(1, $value));
    }

    public function maxPerPage(int $value): self
    {
        return $this->with(maxPerPage: max(1, $value));
    }

    /**
     * Token для Laravel rate-limiter (`RateLimiter::for($token)`).
     *
     * Host-side wire-up: пакет НЕ применяет `throttle:<token>` middleware
     * автоматически — это поле прочитывается host'ом (или CI-проверками) и
     * применяется явным `->middleware('throttle:'.$token)` к
     * `Route::tablesApi(...)` в файле маршрутов. См. closure §13.1 в
     * `.ai-factory/audit/2026-Q2-backend.md`.
     */
    public function rateLimit(?string $token): self
    {
        return $this->with(rateLimit: $token);
    }

    public function maxBulkIds(int $value): self
    {
        return $this->with(maxBulkIds: max(1, $value));
    }

    /**
     * Coarse-grained Gate ability для mutate-эндпоинта.
     *
     * Если `null` (default) — coarse Gate выключен; per-action policy
     * внутри `BulkActionHandler` / `RowActionHandler` / `CellUpdateHandler`
     * остаётся единственным авторизационным слоем.
     *
     * Если установлено — `JsonApiMutateController` после hard-gate
     * `allowMutations` вызывает `Gate::check($ability, $resource)`; при
     * false → 403 `POLICY_DENIED`. Orthogonal к per-action policy.
     */
    public function mutateAbility(?string $ability): self
    {
        return $this->with(mutateAbility: $ability);
    }

    /**
     * Лимит на размер сериализованного `payload` для row/bulk mutate-операций.
     *
     * Сравнение идёт со `strlen(json_encode($payload, JSON_UNESCAPED_UNICODE))`;
     * превышение → 422 `VALIDATION_FAILED` с `reason='payload_too_large'`.
     * Default 64 KiB.
     */
    public function maxPayloadBytes(int $value): self
    {
        return $this->with(maxPayloadBytes: max(1, $value));
    }

    /**
     * @return array<int, string>|null
     */
    public function getAllowFields(): ?array
    {
        return $this->allowFields;
    }

    /**
     * @return array<int, string>|null
     */
    public function getAllowSavedViews(): ?array
    {
        return $this->allowSavedViews;
    }

    public function getAllowMutations(): bool
    {
        return $this->allowMutations;
    }

    public function getDefaultFormat(): FormatMode
    {
        return $this->defaultFormat;
    }

    /**
     * @return array<int, string>
     */
    public function getDefaultIncludes(): array
    {
        return $this->defaultIncludes;
    }

    public function getDefaultPerPage(): int
    {
        return $this->defaultPerPage;
    }

    public function getMaxPerPage(): int
    {
        return $this->maxPerPage;
    }

    public function getRateLimit(): ?string
    {
        return $this->rateLimit;
    }

    public function getMaxBulkIds(): int
    {
        return $this->maxBulkIds;
    }

    public function getMutateAbility(): ?string
    {
        return $this->mutateAbility;
    }

    public function getMaxPayloadBytes(): int
    {
        return $this->maxPayloadBytes;
    }

    /**
     * @param  array<int, string>|null  $allowFields
     * @param  array<int, string>|null  $allowSavedViews
     * @param  array<int, string>|null  $defaultIncludes
     */
    private function with(
        ?array $allowFields = null,
        ?array $allowSavedViews = null,
        ?bool $allowMutations = null,
        ?FormatMode $defaultFormat = null,
        ?array $defaultIncludes = null,
        ?int $defaultPerPage = null,
        ?int $maxPerPage = null,
        ?string $rateLimit = null,
        ?int $maxBulkIds = null,
        ?string $mutateAbility = null,
        ?int $maxPayloadBytes = null,
    ): self {
        return new self(
            allowFields: $allowFields ?? $this->allowFields,
            allowSavedViews: $allowSavedViews ?? $this->allowSavedViews,
            allowMutations: $allowMutations ?? $this->allowMutations,
            defaultFormat: $defaultFormat ?? $this->defaultFormat,
            defaultIncludes: $defaultIncludes ?? $this->defaultIncludes,
            defaultPerPage: $defaultPerPage ?? $this->defaultPerPage,
            maxPerPage: $maxPerPage ?? $this->maxPerPage,
            rateLimit: $rateLimit ?? $this->rateLimit,
            maxBulkIds: $maxBulkIds ?? $this->maxBulkIds,
            mutateAbility: $mutateAbility ?? $this->mutateAbility,
            maxPayloadBytes: $maxPayloadBytes ?? $this->maxPayloadBytes,
        );
    }
}
