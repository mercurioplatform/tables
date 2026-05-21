<?php

namespace Mercurio\Tables\Source;

use Generator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\BuiltinFilterEvaluator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomEvaluator;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Support\CsvFileReader;
use Mercurio\Tables\Source\Support\FileReader;
use Mercurio\Tables\Source\Support\JsonlFileReader;
use Mercurio\Tables\Source\Support\RowValueExtractor;

/**
 * Source-драйвер поверх локального CSV / JSONL / NDJSON файла на диске.
 *
 * Пятый и последний driver-эпик `Source`-абстракции v2 (после
 * {@see EloquentSource}, {@see ArraySource}, {@see SqlSource}, {@see HttpSource}).
 * Файл — non-SQL, non-network источник, у которого поведение драйвера зависит от
 * размера: маленькие файлы читаются целиком и работают как {@see ArraySource}
 * с полным набором capabilities; большие — обрабатываются построчно через
 * lazy reader-generator с сниженными capabilities (`sort=false`, `count=false`)
 * и memory O(1) экспортом через {@see self::stream()}.
 *
 * **Use-cases:**
 * - audit-логи в NDJSON (`storage/audit/events-YYYY-MM.ndjson`) — крупные,
 *   read-only, line-append-only;
 * - batched-импорты CSV (каталоги товаров от поставщиков, прайс-листы);
 * - статичные дампы / справочники (CSV / JSONL рядом с приложением);
 * - ops-dashboards (метрики из json-line логов внешнего процесса);
 * - config-driven каталоги вне `config/`, когда `ArraySource::for(config(...))`
 *   уже не помещается в php-конфиг.
 *
 * **Capabilities по умолчанию** (зависит от режима, выбирается в ctor):
 *
 * | Capability | Materialized | Lazy | Замечание |
 * |---|---|---|---|
 * | `filter`  | `true`            | `true`            | chip-фильтры применяются построчно через {@see BuiltinFilterEvaluator}. |
 * | `sort`    | `true`            | **`false`**       | lazy не материализует; sort требует full scan / external sort. |
 * | `search`  | `true`            | `true`            | sub-string через `mb_stripos`. |
 * | `count`   | `true`            | **`false`**       | lazy → `count()` возвращает `null` (Source contract допускает). |
 * | `cursor`  | `false`           | `false`           | offset-режим в обоих случаях; lazy без `total`/delegate (см. {@see self::page()}). |
 * | `mutate`  | **`false`**       | **`false`**       | Read-only by design — no atomic row rewrite. |
 * | `stream`  | `true`            | `true`            | Memory O(1) экспорт через reader-generator (даже materialized). |
 *
 * Host может явно передать {@see Capabilities} (Capabilities-override), но в lazy
 * режиме `sort=true` / `count=true` / `mutate=true` клампятся обратно к `false`
 * с WARN (lazy не может физически выполнить эти capabilities).
 *
 * **Ограничения:**
 * - **Read-only by design.** `mutate=false` hard-denied; `update()` всегда
 *   бросает `LogicException` + WARN `tables.source.file.mutate_denied`.
 *   Атомарной перезаписи строк CSV/JSONL в v2 нет.
 * - **Только UTF-8.** В CSV-reader'е стрипается BOM `\xEF\xBB\xBF` с первой
 *   колонки header'а; в JSONL — с первой строки. Не-UTF-8 кодировки host обязан
 *   конвертировать `iconv`-ом ДО прокидывания пути.
 * - **Compression out of scope.** `.csv.gz` / `.jsonl.gz` не поддерживаются.
 * - **`qbRoot` (AST из `?qb=`)** поддерживается только в materialized режиме
 *   через {@see AtomEvaluator}; в lazy — `qbRoot` зануляется в клоне Query +
 *   WARN `tables.source.file.qb_unsupported_in_lazy_mode`.
 * - **`sort` недоступен в lazy режиме** — capabilities автоматически снижаются,
 *   `sortField` зануляется в клоне Query + WARN.
 * - **`count() = null` в lazy** — UI пагинатор переключается в offset-режим
 *   без `total`/delegate; кнопки навигации недоступны (известный trade-off для
 *   v2; см. {@see self::page()}).
 * - **{@see SavedView}::scope** (`string`-model-scope / `Closure(Builder)`)
 *   задисейблен — source-agnostic формы `conditions[]` / `sourceClosure` работают
 *   штатно через FilterPipeline / TableBuilder.
 * - **`probe() = null`** — нет Eloquent-модели; type-based authz host
 *   реализует через `Field::canSee` / `RowAction::canRun` вручную.
 * - **Field-aware customizations** (`Field::filterUsing` / `filterScope`) skip +
 *   WARN — те же ограничения, что в {@see ArraySource} (Builder-only API не
 *   применим к in-memory rows).
 * - **`stream()` всегда идёт через reader-generator** — даже в materialized
 *   режиме (memory O(1) экспорт); `qbRoot` и `sort` в `stream` не применяются
 *   ни в одном режиме (explicit trade-off). В отличие от {@see HttpSource::stream},
 *   `FileSource::stream` работает независимо от `cursor`-флага capabilities.
 *
 * **Pipeline-timing контракт (immutable apply):**
 * - {@see self::withQuery()} клонирует входной {@see Query} VO ({@see clone}) и
 *   работает только с клоном — входной `$query` НЕ мутируется. Это сознательное
 *   расхождение с {@see HttpSource::withQuery()}, который мутирует входной VO,
 *   и устанавливает корректный контракт для будущего fix'а в HttpSource.
 * - В **materialized** режиме `withQuery()` применяет полный pipeline
 *   (search → conditions → qbRoot → sort) к `$this->rows` и пробрасывает
 *   filtered Collection в новый instance через ctor-param `$rows`. Дальнейшие
 *   `page()` / `count()` / `find()` оперируют уже-отфильтрованной коллекцией.
 * - В **lazy** режиме `withQuery()` только хранит `$cloned`; фильтрация
 *   применяется построчно при `page()` / `find()` / `stream()`.
 *
 * **Memoization обязательна на стороне host'а.** Factory-вызов
 * (`FileSource::csv(...)` без `$rows`) каждый раз читает файл в ctor для
 * materialized режима. Если host вызывает `Resource::source()` несколько раз
 * за один HTTP-запрос (UI + bulk + counters), без memoize'а в `ListResource`
 * файл будет прочитан N раз. См. `docs/sources.md` — секция «FileSource → Pipeline re-read».
 *
 * **Security note.** FileSource принимает произвольный `string $path` от
 * host'а. Path-sanitization — ответственность host'а
 * (`FileSource::csv(storage_path('catalog/products.csv'))`, не
 * `FileSource::csv(request('path'))`). Не передавайте user-controlled пути.
 */
final class FileSource implements Source
{
    /** Жёсткий верх для materialized режима (50 MB). Превышение → clamp + WARN. */
    private const MATERIALIZE_MAX_BYTES = 50_000_000;

    /** Защита от runaway-pipe / device-файла: stream() не отдаёт больше этого числа rows. */
    private const STREAM_MAX_ROWS = 10_000_000;

    /** При накопленном line-скане в lazy `find()` пишется один WARN на инстанс. */
    private const FIND_LINEAR_SCAN_WARN_AT = 100_000;

    /** Materialized `find()` пишет WARN, если коллекция больше этого порога. */
    private const FIND_LINEAR_SCAN_WARN_THRESHOLD = 10_000;

    private readonly bool $materialized;

    /** @var Collection<int, mixed>|null  null в lazy режиме. */
    private readonly ?Collection $rows;

    private readonly Capabilities $caps;

    private int $findCallsInLazyMode = 0;

    private int $findRowsScannedInLazyMode = 0;

    private bool $findLinearScanWarned = false;

    /**
     * @param  array<int, string>|null  $columns  Заголовки CSV; `null` → читать первую строку.
     * @param  Collection<int, mixed>|null  $rows  Внутренний параметр для {@see self::withQuery()};
     *                                             host обычно не передаёт.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $format,
        public readonly Query $query = new Query,
        ?Capabilities $capabilities = null,
        public readonly ?ListResource $resource = null,
        public readonly string $primaryKey = 'id',
        public readonly int $materializeUnderBytes = 5_000_000,
        public readonly string $delimiter = ',',
        public readonly string $enclosure = '"',
        public readonly string $escape = '\\',
        public readonly ?array $columns = null,
        public readonly bool $strictJson = true,
        ?Collection $rows = null,
    ) {
        if (! is_file($path) || ! is_readable($path)) {
            throw new LogicException("FileSource: path is not a readable file: {$path}");
        }

        if (! in_array($format, ['csv', 'jsonl'], true)) {
            throw new LogicException(
                "FileSource: unsupported format '{$format}'; expected 'csv' or 'jsonl' (NDJSON aliased to JSONL)."
            );
        }

        $effectiveThreshold = $materializeUnderBytes;
        if ($effectiveThreshold > self::MATERIALIZE_MAX_BYTES) {
            Log::warning('tables.source.file.file_too_large_for_materialize', [
                'resource' => $resource?->key(),
                'path' => basename($path),
                'requested' => $materializeUnderBytes,
                'clamped_to' => self::MATERIALIZE_MAX_BYTES,
            ]);
            $effectiveThreshold = self::MATERIALIZE_MAX_BYTES;
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            throw new LogicException("FileSource: filesize() failed for: {$path}");
        }

        $this->materialized = $fileSize <= $effectiveThreshold;
        $this->caps = $this->resolveCapabilities($capabilities, $this->materialized, $path);

        if ($this->materialized) {
            $this->rows = $rows ?? $this->materializeFromFile();
        } else {
            $this->rows = null;
        }
    }

    /**
     * @param  array<int, string>|null  $columns  CSV: имена колонок; `null` → читать заголовок из первой строки.
     */
    public static function for(
        string $path,
        ?string $format = null,
        ?Capabilities $capabilities = null,
        ?ListResource $resource = null,
        string $primaryKey = 'id',
        int $materializeUnderBytes = 5_000_000,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        ?array $columns = null,
        bool $strictJson = true,
    ): self {
        $resolvedFormat = $format ?? self::detectFormat($path);

        return new self(
            path: $path,
            format: $resolvedFormat,
            capabilities: $capabilities,
            resource: $resource,
            primaryKey: $primaryKey,
            materializeUnderBytes: $materializeUnderBytes,
            delimiter: $delimiter,
            enclosure: $enclosure,
            escape: $escape,
            columns: $columns,
            strictJson: $strictJson,
        );
    }

    /**
     * @param  array<int, string>|null  $columns
     */
    public static function csv(
        string $path,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        ?array $columns = null,
        ?Capabilities $capabilities = null,
        ?ListResource $resource = null,
        string $primaryKey = 'id',
        int $materializeUnderBytes = 5_000_000,
    ): self {
        return new self(
            path: $path,
            format: 'csv',
            capabilities: $capabilities,
            resource: $resource,
            primaryKey: $primaryKey,
            materializeUnderBytes: $materializeUnderBytes,
            delimiter: $delimiter,
            enclosure: $enclosure,
            escape: $escape,
            columns: $columns,
        );
    }

    public static function jsonl(
        string $path,
        bool $strictJson = true,
        ?Capabilities $capabilities = null,
        ?ListResource $resource = null,
        string $primaryKey = 'id',
        int $materializeUnderBytes = 5_000_000,
    ): self {
        return new self(
            path: $path,
            format: 'jsonl',
            capabilities: $capabilities,
            resource: $resource,
            primaryKey: $primaryKey,
            materializeUnderBytes: $materializeUnderBytes,
            strictJson: $strictJson,
        );
    }

    public static function ndjson(
        string $path,
        bool $strictJson = true,
        ?Capabilities $capabilities = null,
        ?ListResource $resource = null,
        string $primaryKey = 'id',
        int $materializeUnderBytes = 5_000_000,
    ): self {
        return self::jsonl(
            path: $path,
            strictJson: $strictJson,
            capabilities: $capabilities,
            resource: $resource,
            primaryKey: $primaryKey,
            materializeUnderBytes: $materializeUnderBytes,
        );
    }

    public function capabilities(): Capabilities
    {
        return $this->caps;
    }

    public function withQuery(Query $query): static
    {
        $cloned = clone $query;

        $this->guardSavedViewScope($cloned);

        if ($this->materialized) {
            $rows = $this->rows ?? Collection::make();

            if ($cloned->search !== null && $cloned->searchableColumns !== []) {
                $rows = $this->applySearch($rows, $cloned->search, $cloned->searchableColumns);
            }

            if ($cloned->conditions !== []) {
                $rows = $this->applyConditions($rows, $cloned);
            }

            if ($cloned->qbRoot !== null) {
                $rows = $this->applyQbRoot($rows, $cloned);
            }

            if ($cloned->sortField !== null) {
                $rows = $this->applySort($rows, $cloned);
            }

            $filtered = $rows->values();

            return new self(
                path: $this->path,
                format: $this->format,
                query: $cloned,
                capabilities: $this->caps,
                resource: $this->resource,
                primaryKey: $this->primaryKey,
                materializeUnderBytes: $this->materializeUnderBytes,
                delimiter: $this->delimiter,
                enclosure: $this->enclosure,
                escape: $this->escape,
                columns: $this->columns,
                strictJson: $this->strictJson,
                rows: $filtered,
            );
        }

        if ($cloned->qbRoot !== null) {
            Log::warning('tables.source.file.qb_unsupported_in_lazy_mode', [
                'resource' => $this->resource?->key(),
                'path' => basename($this->path),
                'reason' => 'AtomEvaluator работает только в materialized режиме (требует Collection). Уменьшите файл, увеличьте materializeUnderBytes или используйте chip-фильтры (Query::$conditions).',
            ]);
            $cloned->qbRoot = null;
        }

        if ($cloned->sortField !== null) {
            Log::warning('tables.source.file.sort_unsupported_in_lazy_mode', [
                'resource' => $this->resource?->key(),
                'path' => basename($this->path),
                'field' => $cloned->sortField,
                'reason' => 'Sort требует full materialization / external sort; lazy режим работает построчно. Переключитесь в materialized (понизьте порог) или примите file-order.',
            ]);
            $cloned->sortField = null;
        }

        return new self(
            path: $this->path,
            format: $this->format,
            query: $cloned,
            capabilities: $this->caps,
            resource: $this->resource,
            primaryKey: $this->primaryKey,
            materializeUnderBytes: $this->materializeUnderBytes,
            delimiter: $this->delimiter,
            enclosure: $this->enclosure,
            escape: $this->escape,
            columns: $this->columns,
            strictJson: $this->strictJson,
            rows: null,
        );
    }

    public function count(): ?int
    {
        if ($this->materialized) {
            return $this->rows?->count() ?? 0;
        }

        return null;
    }

    public function page(int $page, int $perPage): Page
    {
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = 1;
        }

        if ($this->materialized) {
            $rows = $this->rows ?? Collection::make();
            $items = $rows->slice(($page - 1) * $perPage, $perPage)->values()->all();
            $total = $rows->count();

            $request = request();
            $delegate = new LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $page,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ],
            );

            return new Page(
                rows: $items,
                total: $total,
                page: $page,
                perPage: $perPage,
                delegate: $delegate,
            );
        }

        $skipCount = ($page - 1) * $perPage;
        $skipped = 0;
        $collected = [];

        foreach ($this->iterateLazy() as $row) {
            if ($skipped < $skipCount) {
                $skipped++;

                continue;
            }
            if (count($collected) >= $perPage) {
                break;
            }
            $collected[] = $row;
        }

        return new Page(
            rows: $collected,
            total: null,
            page: $page,
            perPage: $perPage,
            delegate: null,
        );
    }

    public function stream(int $chunkSize): Generator
    {
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }

        $yielded = 0;

        foreach ($this->iterateLazy() as $row) {
            if ($yielded >= self::STREAM_MAX_ROWS) {
                Log::warning('tables.source.file.stream.cap_exceeded', [
                    'resource' => $this->resource?->key(),
                    'path' => basename($this->path),
                    'cap' => self::STREAM_MAX_ROWS,
                ]);

                throw new LogicException(
                    'FileSource stream exceeded safety cap of '.self::STREAM_MAX_ROWS
                    .' yielded rows; suspected runaway dataset or pipe-style device file.'
                );
            }

            yield $row;
            $yielded++;
        }
    }

    public function find(int|string $id): mixed
    {
        $pk = $this->primaryKey;

        if ($this->materialized) {
            $rows = $this->rows ?? Collection::make();

            if ($rows->count() > self::FIND_LINEAR_SCAN_WARN_THRESHOLD) {
                Log::warning('tables.source.file.find.linear_scan', [
                    'resource' => $this->resource?->key(),
                    'path' => basename($this->path),
                    'size' => $rows->count(),
                    'id' => $id,
                ]);
            }

            $hit = $rows->first(function ($row) use ($pk, $id) {
                $candidate = RowValueExtractor::extract($row, $pk);

                return $candidate !== null && (string) $candidate === (string) $id;
            });

            return $hit;
        }

        $this->findCallsInLazyMode++;
        $reader = $this->buildReader();
        $hit = null;
        $scannedThisCall = 0;

        foreach ($reader->read($this->path) as $row) {
            $scannedThisCall++;
            $value = RowValueExtractor::extract($row, $pk);
            if ($value !== null && (string) $value === (string) $id) {
                $hit = $row;
                break;
            }
        }

        $this->findRowsScannedInLazyMode += $scannedThisCall;
        $this->maybeWarnFindLinearScan();

        return $hit;
    }

    public function findMany(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        $pk = $this->primaryKey;
        $byId = [];

        if ($this->materialized) {
            $rows = $this->rows ?? Collection::make();
            foreach ($rows as $row) {
                $candidate = RowValueExtractor::extract($row, $pk);
                if ($candidate === null) {
                    continue;
                }
                $byId[(string) $candidate] = $row;
            }
        } else {
            $reader = $this->buildReader();
            foreach ($reader->read($this->path) as $row) {
                $candidate = RowValueExtractor::extract($row, $pk);
                if ($candidate === null) {
                    continue;
                }
                $byId[(string) $candidate] = $row;
            }
        }

        $result = [];
        foreach ($ids as $id) {
            $key = (string) $id;
            if (isset($byId[$key])) {
                $result[] = $byId[$key];
            }
        }

        return $result;
    }

    public function update(int|string $id, array $changes): mixed
    {
        Log::warning('tables.source.file.mutate_denied', [
            'resource' => $this->resource?->key(),
            'path' => basename($this->path),
            'id' => $id,
            'columns' => array_keys($changes),
        ]);

        throw new LogicException(
            'FileSource is read-only by design (no atomic row rewrite for CSV/JSONL files in v2).'
        );
    }

    public function probe(): mixed
    {
        return null;
    }

    /**
     * Прямой доступ к underlying Collection — для tests / debugging и для
     * {@see \Mercurio\Tables\SavedView\SavedViewCountsCalculator} universal-path
     * по non-Eloquent sources.
     *
     * В lazy режиме бросает {@see LogicException} + WARN — Collection физически
     * отсутствует. {@see SavedViewCountsCalculator} должен пропускать per-view
     * counts для lazy FileSource-инстансов.
     *
     * @return Collection<int, mixed>
     *
     * @internal
     */
    public function getRows(): Collection
    {
        if (! $this->materialized) {
            Log::warning('tables.source.file.get_rows_unavailable_in_lazy_mode', [
                'resource' => $this->resource?->key(),
                'path' => basename($this->path),
            ]);

            throw new LogicException(
                'FileSource::getRows() unavailable in lazy mode (file > materializeUnderBytes). '
                .'SavedViewCountsCalculator must skip per-view counts for lazy FileSource instances.'
            );
        }

        return $this->rows ?? Collection::make();
    }

    private function resolveCapabilities(?Capabilities $user, bool $materialized, string $path): Capabilities
    {
        if ($user === null) {
            return new Capabilities(
                filter: true,
                sort: $materialized,
                search: true,
                count: $materialized,
                cursor: false,
                mutate: false,
                stream: true,
                qbTree: $materialized,
            );
        }

        $sort = $user->sort;
        $count = $user->count;
        $qbTree = $user->qbTree;

        if (! $materialized && $user->sort) {
            Log::warning('tables.source.file.sort_unsupported_in_lazy_mode', [
                'resource' => $this->resource?->key(),
                'path' => basename($path),
                'reason' => 'capabilities.sort=true ignored in lazy mode (lazy не материализует Collection); clamped to false.',
            ]);
            $sort = false;
        }

        if (! $materialized && $user->count) {
            $count = false;
        }

        if (! $materialized && $user->qbTree) {
            Log::warning('tables.source.file.qb_tree_unsupported_in_lazy_mode', [
                'resource' => $this->resource?->key(),
                'path' => basename($path),
                'reason' => 'capabilities.qbTree=true ignored in lazy mode (AtomEvaluator требует materialized Collection); clamped to false.',
            ]);
            $qbTree = false;
        }

        if ($user->mutate) {
            Log::warning('tables.source.file.mutate_capability_clamped', [
                'resource' => $this->resource?->key(),
                'path' => basename($path),
                'reason' => 'capabilities.mutate=true ignored — FileSource is read-only by design; clamped to false.',
            ]);
        }

        return new Capabilities(
            filter: $user->filter,
            sort: $sort,
            search: $user->search,
            count: $count,
            cursor: $user->cursor,
            mutate: false,
            stream: $user->stream,
            qbTree: $qbTree,
        );
    }

    private function buildReader(): FileReader
    {
        return match ($this->format) {
            'csv' => new CsvFileReader(
                delimiter: $this->delimiter,
                enclosure: $this->enclosure,
                escape: $this->escape,
                columns: $this->columns,
            ),
            'jsonl' => new JsonlFileReader(strictJson: $this->strictJson),
            default => throw new LogicException("FileSource: cannot build reader for format '{$this->format}'."),
        };
    }

    /**
     * @return Collection<int, mixed>
     */
    private function materializeFromFile(): Collection
    {
        $reader = $this->buildReader();
        $rows = [];
        foreach ($reader->read($this->path) as $row) {
            $rows[] = $row;
        }

        return Collection::make($rows)->values();
    }

    /**
     * Лазовый итератор: открывает reader, применяет search + conditions построчно.
     * qbRoot/sort не применяются (в lazy режиме зануляются в `withQuery`).
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function iterateLazy(): Generator
    {
        $reader = $this->buildReader();
        $source = $reader->read($this->path);

        if ($this->query->search !== null && $this->query->searchableColumns !== []) {
            $source = $this->applySearchLazy($source, $this->query->search, $this->query->searchableColumns);
        }

        if ($this->query->conditions !== []) {
            $source = $this->applyConditionsLazy($source, $this->query);
        }

        foreach ($source as $row) {
            yield $row;
        }
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  array<int, string>  $columns
     * @return Collection<int, mixed>
     */
    private function applySearch(Collection $rows, string $needle, array $columns): Collection
    {
        $needleLower = mb_strtolower($needle);

        return $rows->filter(function ($row) use ($columns, $needleLower) {
            foreach ($columns as $column) {
                $value = RowValueExtractor::extract($row, $column);
                if ($value === null) {
                    continue;
                }
                $hay = is_scalar($value) || $value instanceof \Stringable
                    ? mb_strtolower((string) $value)
                    : '';
                if ($hay !== '' && mb_stripos($hay, $needleLower) !== false) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $columns
     * @return Generator<int, array<string, mixed>>
     */
    private function applySearchLazy(iterable $rows, string $needle, array $columns): Generator
    {
        $needleLower = mb_strtolower($needle);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = RowValueExtractor::extract($row, $column);
                if ($value === null) {
                    continue;
                }
                $hay = is_scalar($value) || $value instanceof \Stringable
                    ? mb_strtolower((string) $value)
                    : '';
                if ($hay !== '' && mb_stripos($hay, $needleLower) !== false) {
                    yield $row;

                    continue 2;
                }
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private function applyConditions(Collection $rows, Query $query): Collection
    {
        $this->detectFieldCustomizations($query);

        return $rows->filter(function ($row) use ($query) {
            foreach ($query->conditions as $cond) {
                $value = RowValueExtractor::extract($row, $cond->field);
                if (! BuiltinFilterEvaluator::matches($value, $cond->operator, $cond->value)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return Generator<int, array<string, mixed>>
     */
    private function applyConditionsLazy(iterable $rows, Query $query): Generator
    {
        $this->detectFieldCustomizations($query);

        foreach ($rows as $row) {
            $matches = true;
            foreach ($query->conditions as $cond) {
                $value = RowValueExtractor::extract($row, $cond->field);
                if (! BuiltinFilterEvaluator::matches($value, $cond->operator, $cond->value)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                yield $row;
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private function applyQbRoot(Collection $rows, Query $query): Collection
    {
        $qbRoot = $query->qbRoot;
        if ($qbRoot === null) {
            return $rows;
        }

        $fields = $this->fieldMap();
        $warned = [];
        $this->detectQbCustomizations($qbRoot, $fields, $warned);

        return $rows->filter(fn ($row) => AtomEvaluator::matches($row, $qbRoot));
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private function applySort(Collection $rows, Query $query): Collection
    {
        $field = $query->sortField;
        if ($field === null) {
            return $rows;
        }

        if ($rows->isEmpty()) {
            return $rows;
        }

        $sample = $rows->take(5);
        $nullHits = 0;
        $sampleCount = 0;
        foreach ($sample as $row) {
            $sampleCount++;
            if (RowValueExtractor::extract($row, $field) === null) {
                $nullHits++;
            }
        }
        if ($sampleCount > 0 && $nullHits / $sampleCount >= 0.8) {
            Log::warning('tables.source.file.sort_unknown_field', [
                'resource' => $this->resource?->key(),
                'path' => basename($this->path),
                'field' => $field,
                'sample_size' => $sampleCount,
                'null_hits' => $nullHits,
            ]);

            return $rows;
        }

        return $rows->sortBy(
            fn ($row) => RowValueExtractor::extract($row, $field),
            SORT_NATURAL | SORT_FLAG_CASE,
            $query->sortDirection === 'desc',
        );
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
                Log::warning('tables.source.file.saved_view_scope_unsupported', [
                    'resource' => $this->resource->key(),
                    'view' => $view->key,
                    'reason' => 'SavedView::scope (string model-scope or Closure(Builder)) is Eloquent-only; use SavedView::conditions() or SavedView::sourceClosure() for source-agnostic filtering.',
                ]);
            }

            return;
        }
    }

    private function detectFieldCustomizations(Query $query): void
    {
        if ($this->resource === null || $query->conditions === []) {
            return;
        }

        $fields = $this->fieldMap();
        $warned = [];
        foreach ($query->conditions as $cond) {
            if (isset($warned[$cond->field])) {
                continue;
            }
            $field = $fields[$cond->field] ?? null;
            if ($field !== null && $this->hasFieldCustomization($field)) {
                Log::warning('tables.source.file.field_filter_customization_skipped', [
                    'resource' => $this->resource->key(),
                    'field' => $cond->field,
                    'reason' => 'filterUsing / filterScope (Builder-only) cannot be applied to in-memory rows; falling back to built-in operator semantics.',
                ]);
                $warned[$cond->field] = true;
            }
        }
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, bool>  $warned
     */
    private function detectQbCustomizations(mixed $node, array $fields, array &$warned): void
    {
        if ($node instanceof AtomGroup) {
            foreach ($node->children as $child) {
                $this->detectQbCustomizations($child, $fields, $warned);
            }

            return;
        }

        if ($node instanceof AtomCondition) {
            $field = $fields[$node->field] ?? null;
            if ($field !== null && $this->hasFieldCustomization($field) && ! isset($warned[$node->field])) {
                Log::warning('tables.source.file.field_filter_customization_skipped', [
                    'resource' => $this->resource?->key(),
                    'field' => $node->field,
                    'context' => 'qb',
                    'reason' => 'filterUsing / filterScope (Builder-only) cannot be applied to in-memory rows; falling back to built-in operator semantics.',
                ]);
                $warned[$node->field] = true;
            }
        }
    }

    /**
     * @return array<string, Field>
     */
    private function fieldMap(): array
    {
        if ($this->resource === null) {
            return [];
        }

        $map = [];
        foreach ($this->resource->fieldsMemo() as $field) {
            $map[$field->name] = $field;
        }

        return $map;
    }

    private function hasFieldCustomization(Field $field): bool
    {
        return $field->getFilterUsing() !== null || $field->getFilterScope() !== null;
    }

    private function maybeWarnFindLinearScan(): void
    {
        if ($this->findLinearScanWarned) {
            return;
        }
        if ($this->findRowsScannedInLazyMode < self::FIND_LINEAR_SCAN_WARN_AT) {
            return;
        }

        Log::warning('tables.source.file.find_linear_scan_in_lazy_mode', [
            'resource' => $this->resource?->key(),
            'path' => basename($this->path),
            'total_scanned' => $this->findRowsScannedInLazyMode,
            'find_calls' => $this->findCallsInLazyMode,
            'threshold' => self::FIND_LINEAR_SCAN_WARN_AT,
            'reason' => 'Cumulative find() scans crossed threshold; consider materialized mode or different source for frequent id-lookup on this file.',
        ]);

        $this->findLinearScanWarned = true;
    }

    private static function detectFormat(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'csv' => 'csv',
            'jsonl', 'ndjson' => 'jsonl',
            default => throw new LogicException(
                "FileSource::for(): cannot auto-detect format from extension '.{$ext}'. "
                .'Pass format: \'csv\' or \'jsonl\' explicitly, or use FileSource::csv()/jsonl()/ndjson() shorthand factories.'
            ),
        };
    }
}
