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
use Mercurio\Tables\Source\Support\RowValueExtractor;

/**
 * Source-драйвер поверх in-memory {@see Collection}.
 *
 * Второй полноценный Source в пакете (первый — {@see EloquentSource}). Цель —
 * подключать любые `Resource` к данным из массива / Collection:
 * - справочники из `config/`;
 * - hard-coded settings / lookup-таблицы;
 * - in-memory таблицы тестовых стендов / demo.
 *
 * Capabilities по умолчанию (read-only):
 * - `filter=true`, `sort=true`, `search=true`, `count=true`, `stream=true`;
 * - `cursor=false`, `mutate=false`.
 *
 * Можно переопределить через ctor (например, отключить `sort` для коллекции,
 * порядок которой семантичен).
 *
 * Immutable: {@see self::withQuery()} возвращает новый instance с
 * пре-фильтрованной коллекцией (текущая не мутируется — через `->values()`).
 *
 * Mutate-stack (bulk / row-actions с writer'ом / cell-edit / undo) автоматически
 * прячется UI'ем за счёт `Capabilities::mutate = false` ({@see EloquentSource}
 * остаётся единственным mutate-source в v2). Прямой вызов {@see self::update()}
 * бросает `LogicException` — основная защита от user-инициированных mutate-вызовов
 * уже в контроллерах, которые возвращают 422 при
 * `capabilities()->mutate === false` ДО вызова `update()`.
 */
final class ArraySource implements Source
{
    /**
     * Размер коллекции, выше которого `find()` пишет linear-scan WARN.
     * Большие массивы стоит индексировать на стороне resource'а вручную.
     */
    private const LINEAR_SCAN_WARN_THRESHOLD = 10_000;

    /** @var Collection<int, mixed> */
    private readonly Collection $rows;

    private readonly Capabilities $caps;

    /**
     * @param  Collection<int, mixed>|iterable<int, mixed>  $rows
     * @param  ?ListResource  $resource  Optional — позволяет ArraySource видеть
     *                                   Field-map для detection кастомизаций
     *                                   (`filterUsing` / `filterScope`) и писать
     *                                   `array_source.field_filter_customization_skipped`
     *                                   WARN. Без resource ArraySource всё равно
     *                                   работает (silently fallbacks на built-in
     *                                   evaluator).
     */
    public function __construct(
        Collection|iterable $rows,
        ?Capabilities $caps = null,
        public readonly string $primaryKey = 'id',
        private readonly ?ListResource $resource = null,
    ) {
        $this->rows = $rows instanceof Collection
            ? $rows->values()
            : Collection::make($rows)->values();

        $this->caps = $caps ?? new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: true,
            cursor: false,
            mutate: false,
            stream: true,
            qbTree: true,
        );
    }

    public function capabilities(): Capabilities
    {
        return $this->caps;
    }

    public function withQuery(Query $query): static
    {
        $rows = $this->rows;

        $this->guardSavedViewScope($query);

        if ($query->search !== null && $query->searchableColumns !== []) {
            $rows = $this->applySearch($rows, $query->search, $query->searchableColumns);
        }

        if ($query->conditions !== []) {
            $rows = $this->applyConditions($rows, $query);
        }

        if ($query->qbRoot !== null) {
            $rows = $this->applyQbRoot($rows, $query);
        }

        if ($query->sortField !== null) {
            $rows = $this->applySort($rows, $query);
        }

        $next = new self($rows->values(), $this->caps, $this->primaryKey, $this->resource);

        return $next;
    }

    public function count(): int
    {
        return $this->rows->count();
    }

    public function page(int $page, int $perPage): Page
    {
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = 1;
        }

        $items = $this->rows->slice(($page - 1) * $perPage, $perPage)->values()->all();
        $total = $this->rows->count();

        // LengthAwarePaginator-делегат обязателен: без него Page::previousPageUrl()
        // и Page::nextPageUrl() в offset-режиме возвращают null, и Blade-пагинатор
        // (`tables::pagination-bs5`) остаётся без рабочих кнопок навигации.
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

    public function stream(int $chunkSize): Generator
    {
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }

        foreach ($this->rows->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $row) {
                yield $row;
            }
        }
    }

    public function find(int|string $id): mixed
    {
        $pk = $this->primaryKey;

        if ($this->rows->count() > self::LINEAR_SCAN_WARN_THRESHOLD) {
            Log::warning('tables.array_source.find.linear_scan', [
                'resource' => $this->resource?->key(),
                'size' => $this->rows->count(),
                'id' => $id,
            ]);
        }

        $hit = $this->rows->first(function ($row) use ($pk, $id) {
            $candidate = RowValueExtractor::extract($row, $pk);

            return $candidate !== null && (string) $candidate === (string) $id;
        });

        return $hit;
    }

    public function findMany(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        $pk = $this->primaryKey;

        // Один проход — индексируем найденные rows по строковому id,
        // затем восстанавливаем порядок входного списка.
        $byId = [];
        foreach ($this->rows as $row) {
            $candidate = RowValueExtractor::extract($row, $pk);
            if ($candidate === null) {
                continue;
            }
            $byId[(string) $candidate] = $row;
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
        Log::warning('tables.array_source.update_called_on_readonly', [
            'resource' => $this->resource?->key(),
            'id' => $id,
            'columns' => array_keys($changes),
        ]);

        // Final guard: контроллеры уже возвращают 422 при
        // `capabilities()->mutate === false` ДО вызова update(). Этот
        // exception срабатывает только при программных обходах контроллера.
        throw new LogicException('ArraySource is read-only (Capabilities::mutate=false).');
    }

    public function probe(): mixed
    {
        // Нет Eloquent-модели → null. UI fallback'ится на «показать все actions».
        return null;
    }

    /**
     * Прямой доступ к underlying Collection — для tests / debugging и для
     * SavedViewCountsCalculator (универсальный путь по non-Eloquent sources).
     *
     * @return Collection<int, mixed>
     *
     * @internal
     */
    public function getRows(): Collection
    {
        return $this->rows;
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
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private function applyConditions(Collection $rows, Query $query): Collection
    {
        $fields = $this->fieldMap();

        // Один WARN per-field при detection кастомизаций — кастомизация
        // Field::filterUsing / filterScope нацелена на Builder и на in-memory
        // row применена быть не может. ВСЕ chip-фильтры всё равно проходят
        // через BuiltinFilterEvaluator.
        $warned = [];
        foreach ($query->conditions as $cond) {
            $field = $fields[$cond->field] ?? null;
            if ($field !== null && $this->hasFieldCustomization($field) && ! isset($warned[$cond->field])) {
                Log::warning('tables.array_source.field_filter_customization_skipped', [
                    'resource' => $this->resource?->key(),
                    'field' => $cond->field,
                    'reason' => 'filterUsing / filterScope (Builder-only) cannot be applied to in-memory rows; falling back to built-in operator semantics.',
                ]);
                $warned[$cond->field] = true;
            }
        }

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
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private function applyQbRoot(Collection $rows, Query $query): Collection
    {
        $qbRoot = $query->qbRoot;
        if ($qbRoot === null) {
            return $rows;
        }

        // Field-aware кастомизации в atoms на ArraySource не применяются —
        // тот же WARN, что и для chip-фильтров.
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

        // Unknown field — RowValueExtractor вернёт null для большинства rows.
        // Считаем по первым 5 — если null >= 80% — WARN + skip sort.
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
            Log::warning('tables.array_source.sort_unknown_field', [
                'resource' => $this->resource?->key(),
                'field' => $field,
                'sample_size' => $sampleCount,
                'null_hits' => $nullHits,
            ]);

            return $rows;
        }

        // Callable form — обязательна для dotted-path и Eloquent-accessor'ов
        // (`User::$full_name` через getFullNameAttribute). sortBy(string) на
        // accessor / dotted-path не работает.
        return $rows->sortBy(
            fn ($row) => RowValueExtractor::extract($row, $field),
            SORT_NATURAL | SORT_FLAG_CASE,
            $query->sortDirection === 'desc',
        );
    }

    /**
     * SavedView::scope в обеих формах (string-model-scope и Closure(Builder))
     * принимает Eloquent\Builder и на ArraySource не применим. Source-agnostic
     * формы (conditions[], sourceClosure) работают штатно через FilterPipeline
     * и TableBuilder соответственно.
     */
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
                Log::warning('tables.array_source.saved_view_scope_unsupported', [
                    'resource' => $this->resource->key(),
                    'view' => $view->key,
                    'reason' => 'SavedView::scope (string model-scope or Closure(Builder)) is Eloquent-only; use SavedView::conditions() or SavedView::sourceClosure() for source-agnostic filtering.',
                ]);
            }

            return;
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
                Log::warning('tables.array_source.field_filter_customization_skipped', [
                    'resource' => $this->resource?->key(),
                    'field' => $node->field,
                    'context' => 'qb',
                    'reason' => 'filterUsing / filterScope (Builder-only) cannot be applied to in-memory rows; falling back to built-in operator semantics.',
                ]);
                $warned[$node->field] = true;
            }
        }
    }
}
