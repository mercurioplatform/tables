<?php

namespace Mercurio\Tables\Source;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\BuiltinFilterApplier;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\QueryBuilderApplier;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Support\SqlSourceModel;

/**
 * Source-драйвер поверх произвольного `DB::connection` через inline
 * {@see Model}-subclass {@see SqlSourceModel}. Read-only by default.
 *
 * Use-cases: ClickHouse / read-replica / unmanaged-tables / legacy schemas,
 * для которых в проекте нет EloquentModel. Host вызывает
 * {@see SqlSource::for()} с именем таблицы (и опционально connection /
 * primaryKey) — внутри создаётся inline {@see SqlSourceModel} и
 * оборачивается в Eloquent\Builder, что позволяет переиспользовать весь
 * существующий applier-стек пакета ({@see BuiltinFilterApplier},
 * {@see FilterApplier}, {@see QueryBuilderApplier}) без правок сигнатур.
 *
 * Capabilities по умолчанию:
 * `filter=true, sort=true, search=true, count=true, cursor=false,
 *  mutate=false, stream=true`. Host может включить `mutate=true` через
 * явный {@see Capabilities} (на свою ответственность — {@see SqlSourceModel}
 * не имеет observer'ов / accessors).
 *
 * Ограничения относительно {@see EloquentSource}:
 * - {@see SavedView::$scope} (string-model-scope / Closure(Builder))
 *   задисейблен (WARN + skip): scope-функции ожидают конкретную модель /
 *   relation / local-scope, {@see SqlSourceModel} такого контекста не даёт.
 *   Source-agnostic формы (`conditions()`, `sourceClosure()`) — работают.
 * - search: только single-column LIKE; dotted relation-paths не
 *   поддерживаются (WARN + skip — {@see SqlSourceModel} не имеет relations).
 * - {@see self::findMany()} не сохраняет порядок входного списка id.
 * - {@see self::probe()} возвращает null — type-based authz пакетный
 *   не работает, host реализует {@see Field::canSee} / `RowAction::canRun`
 *   вручную.
 */
final class SqlSource implements Source
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function __construct(
        private readonly Builder $builder,
        private readonly ?Capabilities $capabilities = null,
        private readonly ?ListResource $resource = null,
    ) {}

    /**
     * Фабрика поверх inline Eloquent {@see SqlSourceModel} — основной entry
     * point для host'а. Конфигурирует connection / table / primaryKey /
     * timestamps=false на голом subclass'е {@see Model} (без relations /
     * scopes / observers / accessors) и передаёт `$model->newQuery()` в
     * конструктор. Concrete subclass {@see SqlSourceModel} вместо anonymous —
     * намеренно: PHPStan template-invariance не допускает
     * `Builder<Model@anonymous>` ↦ `Builder<Model>`, а функционально разницы
     * нет (relations / observers / accessors отсутствуют в обоих случаях).
     */
    public static function for(
        string $table,
        ?string $connection = null,
        string $primaryKey = 'id',
        ?Capabilities $capabilities = null,
        ?ListResource $resource = null,
    ): self {
        $model = new SqlSourceModel;

        if ($connection !== null) {
            $model->setConnection($connection);
        }

        $model->setTable($table);
        $model->setKeyName($primaryKey);

        return new self($model->newQuery(), $capabilities, $resource);
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities ?? new Capabilities(
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

    public function withQuery(Query $query): static
    {
        $cloned = clone $this->builder;

        $this->applySearch($cloned, $query);
        $this->applySavedView($query);
        $this->applyConditions($cloned, $query);
        $this->applyQb($cloned, $query);
        $this->applySort($cloned, $query);

        return new self($cloned, $this->capabilities ?? $this->capabilities(), $this->resource);
    }

    public function count(): int
    {
        return (int) (clone $this->builder)->toBase()->getCountForPagination();
    }

    public function page(int $page, int $perPage): Page
    {
        $delegate = (clone $this->builder)
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        \assert($delegate instanceof LengthAwarePaginator);

        return new Page(
            rows: $delegate->items(),
            total: $delegate->total(),
            page: $delegate->currentPage(),
            perPage: $delegate->perPage(),
            delegate: $delegate,
        );
    }

    public function stream(int $chunkSize): Generator
    {
        foreach ((clone $this->builder)->lazyById($chunkSize) as $row) {
            yield $row;
        }
    }

    public function find(int|string $id): mixed
    {
        $result = (clone $this->builder)->whereKey($id)->first();

        return $result;
    }

    public function findMany(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        $rows = (clone $this->builder)->whereKey($ids)->get()->all();

        return $rows;
    }

    public function update(int|string $id, array $changes): mixed
    {
        if (! $this->capabilities()->mutate) {
            Log::warning('tables.source.sql.mutate_denied', [
                'resource' => $this->resource?->key(),
                'id' => $id,
            ]);

            throw new LogicException(
                'SqlSource is read-only by default. Pass Capabilities(mutate: true) to enable.'
            );
        }

        (clone $this->builder)->whereKey($id)->update($changes);

        return (clone $this->builder)->whereKey($id)->first();
    }

    public function probe(): mixed
    {
        return null;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySearch(Builder $query, Query $q): void
    {
        if ($q->search === null || $q->searchableColumns === []) {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q->search).'%';

        $query->where(function (Builder $inner) use ($q, $like): void {
            foreach ($q->searchableColumns as $column) {
                if (str_contains($column, '.')) {
                    Log::warning('tables.source.sql.search_dotted_unsupported', [
                        'resource' => $this->resource?->key(),
                        'column' => $column,
                        'reason' => 'SqlSource uses anonymous Model without relations; dotted search paths cannot be resolved.',
                    ]);

                    continue;
                }

                $inner->orWhere($column, 'LIKE', $like);
            }
        });
    }

    /**
     * Guard-only: source-agnostic формы SavedView ({@see SavedView::conditions},
     * {@see SavedView::sourceClosure}) применяются в FilterPipeline и
     * TableBuilder соответственно — здесь они уже учтены. Осталось
     * задисейблить только {@see SavedView::$scope} — у anonymous-Model
     * нет model-scope / local-scope / relations.
     */
    private function applySavedView(Query $q): void
    {
        if ($q->savedViewKey === null || $this->resource === null) {
            return;
        }

        foreach ($this->resource->savedViewsMemo() as $view) {
            if ($view->key !== $q->savedViewKey) {
                continue;
            }

            if ($view->scope !== null) {
                Log::warning('tables.source.sql.saved_view_scope_unsupported', [
                    'resource' => $this->resource->key(),
                    'view' => $view->key,
                    'reason' => 'SavedView::scope is Eloquent-only; use conditions() or sourceClosure().',
                ]);
            }

            return;
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyConditions(Builder $query, Query $q): void
    {
        if ($q->conditions === []) {
            return;
        }

        $fields = $this->resourceFieldMap();

        foreach ($q->conditions as $cond) {
            $field = $fields[$cond->field] ?? null;
            if ($field !== null) {
                FilterApplier::apply($query, $field, $cond);

                continue;
            }

            BuiltinFilterApplier::apply($query, $cond->field, $cond->operator, $cond->value);
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyQb(Builder $query, Query $q): void
    {
        if ($q->qbRoot === null || $this->resource === null) {
            return;
        }

        $qbRoot = $q->qbRoot;
        $resource = $this->resource;

        $query->where(function (Builder $sub) use ($qbRoot, $resource): void {
            if ($qbRoot instanceof AtomGroup) {
                QueryBuilderApplier::apply($sub, $qbRoot, $resource);

                return;
            }

            $singleGroup = new AtomGroup('AND', false, [$qbRoot]);
            QueryBuilderApplier::apply($sub, $singleGroup, $resource);
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySort(Builder $query, Query $q): void
    {
        if ($q->sortField === null) {
            return;
        }

        $direction = $q->sortDirection === 'desc' ? 'desc' : 'asc';
        $query->orderBy($q->sortField, $direction);
    }

    /**
     * @return array<string, Field>
     */
    private function resourceFieldMap(): array
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
}
