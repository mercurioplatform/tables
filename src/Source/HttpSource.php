<?php

namespace Mercurio\Tables\Source;

use Closure;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Support\HttpFetchResult;

/**
 * Source-драйвер поверх произвольного внешнего HTTP API через декларативный
 * fetch-closure поверх neutral {@see Query} VO. Read-only by design.
 *
 * Host передаёт `Closure(Query, ?string $cursor): array{rows, nextCursor, prevCursor, total}`,
 * драйвер сам делает capabilities-gating, кэширование (Laravel `Cache::remember`),
 * пишет Log-каналы и собирает {@see Page} в правильном режиме (cursor / offset).
 * Pipeline пакета (filter/sort/search/SavedView) работает поверх HttpSource
 * без правок: Resource подключает источник, остальное общее.
 *
 * Use-cases:
 * - Shopify Admin API — orders / products / customers (cursor-only API, нет total);
 * - Stripe API — payments / charges / customers (cursor-pagination);
 * - GitHub REST API — issues / PRs (cursor-pagination);
 * - внутренние REST / gRPC bridge-сервисы — read-only admin-список поверх
 *   данных, которые лежат в другом микросервисе.
 *
 * Capabilities по умолчанию:
 * `filter=true, sort=true, search=true, count=false, cursor=true,
 *  mutate=false, stream=true`. Host может явно передать `Capabilities(count: true)`
 * для offset-режима (тогда fetch обязан возвращать `total` в payload'е),
 * но не `mutate: true` — `update()` всегда бросает {@see LogicException}.
 *
 * Ограничения относительно {@see EloquentSource}:
 * - **cursor primary**: pagination по умолчанию через `nextCursor` /
 *   `prevCursor`; offset-mode частично функционален (без
 *   `LengthAwarePaginator`-делегата `Page::previousPageUrl()` /
 *   `Page::nextPageUrl()` в offset-режиме вернут `null` — Blade-пагинатор
 *   рендерит «← / →» вместо номеров страниц);
 * - **`qbRoot` задисейблен** (WARN `tables.source.http.qb_unsupported` + skip):
 *   HttpSource не транслирует Query Builder AST в HTTP-параметры. Используйте
 *   chip-фильтры; если QB-tree всё-таки нужен, host вытаскивает его в
 *   собственном fetch-closure (HttpSource его не пробрасывает);
 * - **`mutate` hard-denied**: `update()` бросает {@see LogicException}
 *   всегда — нет override через `Capabilities(mutate: true)`. Host пишет в API
 *   через свой service-layer, не через `Source::update()`;
 * - **`find` fallback** идёт через chip-фильтр `primaryKey = $id` +
 *   `withQuery(...)->page(1, 1)`. Closure-injection (`HttpSource::for(..., findOne: ...)`)
 *   даёт O(1)-путь;
 * - **`findMany` bulk-fallback** через `In`-условие на `primaryKey` +
 *   `withQuery(...)->page(1, count($ids))` — один HTTP-запрос. Если `In` не
 *   входит в whitelist для primaryKey — degrade на N×`find()` с
 *   WARN `tables.source.http.find_many.linear_fallback`. Closure-injection
 *   (`findMany: ...`) даёт самый быстрый путь;
 * - **`findMany` не сохраняет порядок** входного списка ids (consistent с
 *   {@see SqlSource} — известное отклонение от
 *   `Source::findMany()` PHPDoc-контракта «в исходном порядке id»);
 * - **`SavedView::scope`** (string-model-scope или `Closure(Builder)`) задисейблен
 *   (WARN `tables.source.http.saved_view_scope_unsupported` + skip). Используйте
 *   source-agnostic формы: `SavedView::conditions()`, `SavedView::sourceClosure()`;
 * - **`probe(): null`** — нет Eloquent-модели, type-based authz пакетный не
 *   работает (host реализует `Field::canSee` / `RowAction::canRun` вручную);
 * - **Field-aware customizations** (`Field::applyFilter`, `filterUsing`,
 *   `filterScope`) не применяются — нет `Eloquent\Builder`, операторы идут в
 *   payload через built-in semantics + per-field operator whitelist;
 * - **Per-field operator whitelist** — host передаёт
 *   `array<field, list<Operator>>`: для каждого поля в whitelist оставляем
 *   только разрешённые операторы (skip + WARN иначе); поля без entry в
 *   whitelist — no constraint (API понимает любые операторы по этим полям).
 *
 * Кэширование: TTL fixed через ctor (`cacheTtlSeconds`); ключ детерминированно
 * собирается через `json_encode` явных Query-полей + cursor. Host при write
 * вызывает `Cache::forget(...)` сам или ждёт TTL — HttpSource не отслеживает
 * write-through, потому что `mutate` выключен by design. Thundering-herd
 * lock — на следующую фазу; для v2 простой `Cache::remember`.
 */
final class HttpSource implements Source
{
    private const STREAM_MAX_ITERATIONS = 10_000;

    private const STREAM_MAX_YIELDED_ROWS = 1_000_000;

    /**
     * @param  Closure(Query, ?string): array<string, mixed>  $fetch
     * @param  ?Closure(int|string): mixed  $findOne
     * @param  ?Closure(array<int, int|string>): iterable<int, mixed>  $findMany
     * @param  ?array<string, list<Operator>>  $operatorWhitelist
     */
    public function __construct(
        private readonly Closure $fetch,
        private readonly Query $query = new Query,
        private readonly ?Capabilities $capabilities = null,
        private readonly ?ListResource $resource = null,
        private readonly ?Closure $findOne = null,
        private readonly ?Closure $findMany = null,
        private readonly ?array $operatorWhitelist = null,
        private readonly ?int $cacheTtlSeconds = null,
        private readonly ?string $cachePrefix = null,
        private readonly string $primaryKey = 'id',
        private readonly ?string $cursor = null,
    ) {}

    /**
     * Публичный entry-point. Контракт payload'а fetch-closure:
     * ```
     * fn(Query $q, ?string $cursor): array{
     *     rows: array<int, mixed>,
     *     nextCursor: ?string,
     *     prevCursor: ?string,    // опц.
     *     total: ?int,            // только при capabilities.count=true
     * }
     * ```
     *
     * @param  Closure(Query, ?string): array<string, mixed>  $fetch
     * @param  ?Closure(int|string): mixed  $findOne
     * @param  ?Closure(array<int, int|string>): iterable<int, mixed>  $findMany
     * @param  ?array<string, list<Operator>>  $operatorWhitelist
     */
    public static function for(
        Closure $fetch,
        ?Capabilities $capabilities = null,
        ?ListResource $resource = null,
        ?Closure $findOne = null,
        ?Closure $findMany = null,
        ?array $operatorWhitelist = null,
        ?int $cacheTtlSeconds = null,
        ?string $cachePrefix = null,
        string $primaryKey = 'id',
    ): self {
        return new self(
            fetch: $fetch,
            query: new Query,
            capabilities: $capabilities,
            resource: $resource,
            findOne: $findOne,
            findMany: $findMany,
            operatorWhitelist: $operatorWhitelist,
            cacheTtlSeconds: $cacheTtlSeconds,
            cachePrefix: $cachePrefix,
            primaryKey: $primaryKey,
            cursor: null,
        );
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities ?? new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: false,
            cursor: true,
            mutate: false,
            stream: true,
        );
    }

    public function withQuery(Query $query): static
    {
        $applied = clone $query;
        $this->applyOperatorWhitelist($applied);
        $this->guardSavedViewScope($applied);
        $this->guardQbRoot($applied);

        return new self(
            fetch: $this->fetch,
            query: $applied,
            capabilities: $this->capabilities,
            resource: $this->resource,
            findOne: $this->findOne,
            findMany: $this->findMany,
            operatorWhitelist: $this->operatorWhitelist,
            cacheTtlSeconds: $this->cacheTtlSeconds,
            cachePrefix: $this->cachePrefix,
            primaryKey: $this->primaryKey,
            cursor: null,
        );
    }

    public function count(): ?int
    {
        if (! $this->capabilities()->count) {
            return null;
        }

        $result = $this->fetchPage('offset:1');

        return $result->total;
    }

    public function page(int $page, int $perPage): Page
    {
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = 1;
        }

        if (! $this->capabilities()->count) {
            $result = $this->fetchPage($this->cursor);

            return new Page(
                rows: $result->rows,
                total: null,
                page: 1,
                perPage: $perPage,
                nextCursor: $result->nextCursor,
                prevCursor: $result->prevCursor,
            );
        }

        $result = $this->fetchPage('offset:'.$page);

        return new Page(
            rows: $result->rows,
            total: $result->total ?? 0,
            page: $page,
            perPage: $perPage,
        );
    }

    public function stream(int $chunkSize): Generator
    {
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }

        if (! $this->capabilities()->cursor) {
            Log::warning('tables.source.http.cursor_required_for_stream', [
                'resource' => $this->resource?->key(),
                'reason' => 'capabilities.cursor=false: stream offset-mode не поддерживается во избежание бесконечной пагинации; yield только первой страницы',
            ]);

            $result = $this->fetchPage(null);
            foreach ($result->rows as $row) {
                yield $row;
            }

            return;
        }

        $cursor = null;
        $iterations = 0;
        $yielded = 0;

        while (true) {
            $result = $this->fetchPage($cursor);

            foreach ($result->rows as $row) {
                yield $row;
                $yielded++;
                if ($yielded >= self::STREAM_MAX_YIELDED_ROWS) {
                    throw new LogicException(
                        'HttpSource stream exceeded safety cap of '.self::STREAM_MAX_YIELDED_ROWS
                        .' yielded rows; suspected infinite cursor loop or runaway dataset.'
                    );
                }
            }

            if ($result->nextCursor === null) {
                break;
            }

            $cursor = $result->nextCursor;
            $iterations++;

            if ($iterations >= self::STREAM_MAX_ITERATIONS) {
                throw new LogicException(
                    'HttpSource stream exceeded safety cap of '.self::STREAM_MAX_ITERATIONS
                    .' iterations; suspected infinite cursor loop.'
                );
            }
        }
    }

    public function find(int|string $id): mixed
    {
        if ($this->findOne !== null) {
            $result = ($this->findOne)($id);
            $hit = $result !== null && $result !== [];

            return $hit ? $result : null;
        }

        if ($this->operatorWhitelist !== null
            && array_key_exists($this->primaryKey, $this->operatorWhitelist)
            && ! in_array(Operator::Eq, $this->operatorWhitelist[$this->primaryKey], true)
        ) {
            Log::warning('tables.source.http.find_fallback_blocked_by_whitelist', [
                'resource' => $this->resource?->key(),
                'primary_key' => $this->primaryKey,
                'allowed' => array_map(
                    fn (Operator $op): string => $op->value,
                    $this->operatorWhitelist[$this->primaryKey],
                ),
                'reason' => 'Operator::Eq не входит в whitelist для primary_key; find() fallback недоступен. Передайте `findOne`-closure в HttpSource::for() либо добавьте Operator::Eq в whitelist.',
            ]);

            return null;
        }

        $fallbackQuery = new Query;
        $fallbackQuery->conditions = [
            new FilterCondition($this->primaryKey, Operator::Eq, $id),
        ];

        $page = $this->withQuery($fallbackQuery)->page(1, 1);
        $row = $page->items()[0] ?? null;

        return $row;
    }

    public function findMany(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        if ($this->findMany !== null) {
            $result = ($this->findMany)($ids);
            $rows = is_array($result) ? $result : iterator_to_array($result, false);

            return $rows;
        }

        $inBlocked = $this->operatorWhitelist !== null
            && array_key_exists($this->primaryKey, $this->operatorWhitelist)
            && ! in_array(Operator::In, $this->operatorWhitelist[$this->primaryKey], true);

        if (! $inBlocked) {
            $bulkQuery = new Query;
            $bulkQuery->conditions = [
                new FilterCondition($this->primaryKey, Operator::In, $ids),
            ];

            $page = $this->withQuery($bulkQuery)->page(1, count($ids));

            return $page->items();
        }

        Log::warning('tables.source.http.find_many.linear_fallback', [
            'resource' => $this->resource?->key(),
            'count' => count($ids),
            'primary_key' => $this->primaryKey,
            'reason' => 'Operator::In не входит в whitelist для primary_key; degrade на N×find() loop. Передайте `findMany`-closure в HttpSource::for() для O(1)-пути либо добавьте Operator::In в whitelist.',
        ]);

        $rows = [];
        foreach ($ids as $id) {
            $row = $this->find($id);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function update(int|string $id, array $changes): mixed
    {
        Log::warning('tables.source.http.mutate_denied', [
            'resource' => $this->resource?->key(),
            'id' => $id,
            'columns' => array_keys($changes),
            'reason' => 'HttpSource is read-only by design; mutate=true не предусмотрено. Пишите в API через host service-layer.',
        ]);

        throw new LogicException(
            'HttpSource is read-only by design. Mutate API to be implemented via host service layer.'
        );
    }

    public function probe(): mixed
    {
        return null;
    }

    private function fetchPage(?string $cursor): HttpFetchResult
    {
        if ($this->cacheTtlSeconds === null || $this->cacheTtlSeconds === 0) {
            return $this->liveFetch($cursor);
        }

        $key = $this->buildCacheKey($cursor);
        $cached = Cache::get($key);

        if ($cached instanceof HttpFetchResult) {
            return $cached;
        }

        $result = $this->liveFetch($cursor);
        Cache::put($key, $result, $this->cacheTtlSeconds);

        return $result;
    }

    private function liveFetch(?string $cursor): HttpFetchResult
    {
        $payload = ($this->fetch)($this->query, $cursor);

        $result = HttpFetchResult::fromArray($payload, $this->resource?->key());

        return $result;
    }

    private function buildCacheKey(?string $cursor): string
    {
        $conditions = [];
        foreach ($this->query->conditions as $cond) {
            $conditions[] = [
                'field' => $cond->field,
                'operator' => $cond->operator->value,
                'value' => $cond->value,
            ];
        }
        usort($conditions, function (array $a, array $b): int {
            return [$a['field'], $a['operator']] <=> [$b['field'], $b['operator']];
        });

        $searchable = $this->query->searchableColumns;
        sort($searchable);

        $payload = [
            'search' => $this->query->search,
            'searchable' => $searchable,
            'conditions' => $conditions,
            'sort_field' => $this->query->sortField,
            'sort_direction' => $this->query->sortDirection,
            'saved_view' => $this->query->savedViewKey,
        ];

        $serialized = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $prefix = $this->cachePrefix ?? 'tables.http';
        $resourceKey = $this->resource?->key() ?? 'anonymous';

        return $prefix.'.'.$resourceKey.'.'.sha1($serialized.':'.($cursor ?? ''));
    }

    private function applyOperatorWhitelist(Query $query): void
    {
        if ($this->operatorWhitelist === null || $query->conditions === []) {
            return;
        }

        $kept = [];
        foreach ($query->conditions as $cond) {
            $allowed = $this->operatorWhitelist[$cond->field] ?? null;

            if ($allowed === null) {
                $kept[] = $cond;

                continue;
            }

            if (in_array($cond->operator, $allowed, true)) {
                $kept[] = $cond;

                continue;
            }

            Log::warning('tables.source.http.operator_not_allowed', [
                'resource' => $this->resource?->key(),
                'field' => $cond->field,
                'operator' => $cond->operator->value,
                'allowed' => array_map(fn (Operator $op): string => $op->value, $allowed),
                'reason' => 'Оператор не входит в per-field whitelist; condition skipped перед fetch. Уточните whitelist или конвертируйте оператор в host fetch-closure.',
            ]);
        }

        $query->conditions = $kept;
    }

    private function guardSavedViewScope(Query $query): void
    {
        if ($query->savedViewKey === null || $this->resource === null) {
            return;
        }

        foreach ($this->resource->savedViewsMemo() as $view) {
            if ($view->key !== $query->savedViewKey) {
                continue;
            }

            if ($view->scope !== null) {
                Log::warning('tables.source.http.saved_view_scope_unsupported', [
                    'resource' => $this->resource->key(),
                    'view' => $view->key,
                    'reason' => 'SavedView::scope (string-model-scope или Closure(Builder)) — Eloquent-only; HttpSource не оборачивает Builder. Используйте source-agnostic формы: SavedView::conditions() или SavedView::sourceClosure().',
                ]);
            }

            return;
        }
    }

    private function guardQbRoot(Query $query): void
    {
        if ($query->qbRoot === null) {
            return;
        }

        Log::warning('tables.source.http.qb_unsupported', [
            'resource' => $this->resource?->key(),
            'reason' => 'HttpSource не транслирует Query Builder AST (?qb=) в HTTP-параметры. Используйте chip-фильтры; если QB-tree нужен, обрабатывайте его в собственном fetch-closure (HttpSource его не пробрасывает).',
        ]);

        $query->qbRoot = null;
    }
}
