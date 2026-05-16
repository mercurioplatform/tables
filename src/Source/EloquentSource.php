<?php

namespace Mercurio\Tables\Source;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\BuiltinFilterApplier;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\QueryBuilderApplier;
use Mercurio\Tables\ListResource;
use Throwable;

/**
 * Source-адаптер поверх Eloquent\Builder.
 *
 * В Phase 1 — единственная реализация {@see Source}. Сохраняет всю
 * существующую SQL-семантику: chip-фильтры (через Field-aware customizations:
 * applyFilter/Using/Scope + built-in fallback), Query Builder AST (?qb=…),
 * search через LIKE по searchable() колонкам, SavedView-scope (model-scope
 * или Closure), offset-пагинация с LengthAwarePaginator-делегатом.
 *
 * Field-aware customizations работают только когда передан $resource —
 * это deprecation-shim case ({@see ListResource::resolveSource()}). При
 * прямом построении `new EloquentSource($builder)` Field-resolution
 * отключён, но built-in операторы и raw search/sort/savedView (Closure)
 * остаются доступны.
 */
final class EloquentSource implements Source
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function __construct(
        private readonly Builder $builder,
        private readonly ?ListResource $resource = null,
    ) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: true,
            cursor: false,
            mutate: true,
            stream: true,
        );
    }

    public function withQuery(Query $query): static
    {
        $cloned = clone $this->builder;

        $this->applySearch($cloned, $query);
        $this->applySavedView($cloned, $query);
        $this->applyConditions($cloned, $query);
        $this->applyQb($cloned, $query);
        $this->applySort($cloned, $query);

        Log::debug('tables.source.eloquent.with_query', [
            'resource' => $this->resource?->key(),
            'search' => $query->search !== null,
            'conditions' => count($query->conditions),
            'qb' => $query->qbRoot !== null,
            'sort' => $query->sortField,
            'saved_view' => $query->savedViewKey,
        ]);

        return new self($cloned, $this->resource);
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

        Log::debug('tables.source.eloquent.page', [
            'resource' => $this->resource?->key(),
            'page' => $delegate->currentPage(),
            'per_page' => $delegate->perPage(),
            'total' => $delegate->total(),
        ]);

        return new Page(
            rows: $delegate->items(),
            total: $delegate->total(),
            page: $delegate->currentPage(),
            perPage: $delegate->perPage(),
            nextCursor: null,
            prevCursor: null,
            delegate: $delegate,
        );
    }

    public function stream(int $chunkSize): Generator
    {
        Log::debug('tables.source.eloquent.stream.start', [
            'resource' => $this->resource?->key(),
            'chunk_size' => $chunkSize,
        ]);

        // lazyById даёт memory O(chunkSize): внутри chunkById,
        // снаружи — обычный foreach без материализации полной выборки.
        foreach ((clone $this->builder)->lazyById($chunkSize) as $row) {
            yield $row;
        }
    }

    public function find(int|string $id): mixed
    {
        $result = (clone $this->builder)->whereKey($id)->first();

        Log::debug('tables.source.eloquent.find', [
            'resource' => $this->resource?->key(),
            'id' => $id,
            'hit' => $result !== null,
        ]);

        return $result;
    }

    public function findMany(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        $rows = (clone $this->builder)->whereKey($ids)->get()->all();

        Log::debug('tables.source.eloquent.find_many', [
            'resource' => $this->resource?->key(),
            'requested' => count($ids),
            'found' => count($rows),
        ]);

        return $rows;
    }

    public function update(int|string $id, array $changes): mixed
    {
        if (! $this->capabilities()->mutate) {
            Log::warning('tables.source.eloquent.mutate_denied', [
                'resource' => $this->resource?->key(),
                'id' => $id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($id, $changes) {
            $model = (clone $this->builder)->whereKey($id)->first();
            if ($model === null) {
                Log::warning('tables.source.eloquent.update.missing', [
                    'resource' => $this->resource?->key(),
                    'id' => $id,
                ]);

                return null;
            }

            $model->update($changes);

            Log::debug('tables.source.eloquent.update.ok', [
                'resource' => $this->resource?->key(),
                'id' => $id,
                'columns' => array_keys($changes),
            ]);

            return $model->fresh();
        });
    }

    public function probe(): mixed
    {
        try {
            return (clone $this->builder)->getModel()->newInstance();
        } catch (Throwable $e) {
            Log::warning('tables.source.eloquent.probe_failed', [
                'resource' => $this->resource?->key(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Прямой доступ к underlying Builder для legacy-кода, который ещё не
     * перешёл на Source-API (ExportHandler до T12, host-консьюмеры
     * `exportState()['builder']`). Удаляется в v3 вместе с deprecation-shim.
     *
     * @return Builder<Model>
     *
     * @internal
     */
    public function getBuilder(): Builder
    {
        return clone $this->builder;
    }

    /**
     * @param  Builder<Model>  $query
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
                    [$relation, $relCol] = explode('.', $column, 2);
                    $inner->orWhereHas($relation, function (Builder $sub) use ($relCol, $like): void {
                        $sub->where($relCol, 'LIKE', $like);
                    });
                } else {
                    $inner->orWhere($column, 'LIKE', $like);
                }
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySavedView(Builder $query, Query $q): void
    {
        if ($q->savedViewKey === null || $this->resource === null) {
            return;
        }

        foreach ($this->resource->savedViewsMemo() as $view) {
            if ($view->key === $q->savedViewKey) {
                $view->apply($query);

                return;
            }
        }
    }

    /**
     * @param  Builder<Model>  $query
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
     * @param  Builder<Model>  $query
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

            // AtomCondition корневого уровня — оборачиваем как single-child group.
            $singleGroup = new AtomGroup('AND', false, [$qbRoot]);
            QueryBuilderApplier::apply($sub, $singleGroup, $resource);
        });
    }

    /**
     * @param  Builder<Model>  $query
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
