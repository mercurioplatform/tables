<?php

namespace Mercurio\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

abstract class ListResource
{
    abstract public function key(): string;

    abstract public function query(): Builder;

    /**
     * @return array<int, Field>
     */
    abstract public function fields(): array;

    /**
     * @return array<int, string>
     */
    public function searchable(): array
    {
        return [];
    }

    /**
     * @return array<int, SavedView>
     */
    public function savedViews(): array
    {
        return [];
    }

    /**
     * @return array<int, BulkAction>
     */
    public function bulkActions(): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function rowActions(): array
    {
        return [];
    }

    public function perPage(): int
    {
        return (int) config('tables.default_per_page', 25);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function defaultSort(): ?array
    {
        return null;
    }

    public function density(): string
    {
        return 'comfortable';
    }

    public function summary(): ?Summary
    {
        return null;
    }

    private function normalizeDensity(string $raw): string
    {
        return in_array($raw, ['compact', 'comfortable'], true) ? $raw : 'comfortable';
    }

    public function table(Request $request): ResourceTable
    {
        $query = $this->query();
        $fields = $this->fields();
        $savedViews = $this->savedViews();

        $search = $this->normalizeSearch($request->query('q'));
        if ($search !== null) {
            $this->applySearch($query, $search);
        }

        $currentView = $this->resolveCurrentView($request->query('view'), $savedViews);
        if ($currentView !== null) {
            $this->findView($currentView, $savedViews)?->apply($query);
        }

        $sort = $this->resolveSort(
            $request->query('sort'),
            $request->query('dir'),
            $fields,
        );
        if ($sort !== null) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        $paginator = $query
            ->paginate($this->perPage())
            ->withQueryString();

        $summary = $this->summary();

        Log::debug('tables.list', [
            'key' => $this->key(),
            'q' => $search,
            'view' => $currentView,
            'sort' => $sort,
            'per_page' => $this->perPage(),
            'total' => $paginator->total(),
            'density' => $this->density(),
            'summary' => $summary !== null ? class_basename($summary) : null,
        ]);

        return new ResourceTable(
            key: $this->key(),
            paginator: $paginator,
            fields: $fields,
            savedViews: $savedViews,
            bulkActions: $this->bulkActions(),
            rowActions: $this->rowActions(),
            sort: $sort,
            currentView: $currentView,
            search: $search,
            density: $this->normalizeDensity($this->density()),
            summary: $summary,
        );
    }

    private function normalizeSearch(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 200);
    }

    private function applySearch(Builder $query, string $term): void
    {
        $columns = $this->searchable();
        if ($columns === []) {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $query->where(function (Builder $inner) use ($columns, $like): void {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $relCol] = explode('.', $column, 2);
                    $inner->orWhereHas($relation, function (Builder $q) use ($relCol, $like): void {
                        $q->where($relCol, 'LIKE', $like);
                    });
                } else {
                    $inner->orWhere($column, 'LIKE', $like);
                }
            }
        });
    }

    /**
     * @param  array<int, SavedView>  $savedViews
     */
    private function resolveCurrentView(mixed $raw, array $savedViews): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        foreach ($savedViews as $view) {
            if ($view->key === $raw) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * @param  array<int, SavedView>  $savedViews
     */
    private function findView(string $key, array $savedViews): ?SavedView
    {
        foreach ($savedViews as $view) {
            if ($view->key === $key) {
                return $view;
            }
        }

        return null;
    }

    /**
     * @param  array<int, Field>  $fields
     * @return array{column: string, direction: string}|null
     */
    private function resolveSort(mixed $rawSort, mixed $rawDir, array $fields): ?array
    {
        $direction = is_string($rawDir) && strtolower($rawDir) === 'desc' ? 'desc' : 'asc';

        if (is_string($rawSort) && $rawSort !== '') {
            foreach ($fields as $field) {
                if ($field->name === $rawSort && $field->isSortable()) {
                    return ['column' => $rawSort, 'direction' => $direction];
                }
            }

            $default = $this->defaultSort();
            if ($default !== null) {
                $defaultDir = strtolower($default[1]) === 'desc' ? 'desc' : 'asc';

                return ['column' => $default[0], 'direction' => $defaultDir];
            }

            return null;
        }

        $default = $this->defaultSort();
        if ($default !== null) {
            $defaultDir = strtolower($default[1]) === 'desc' ? 'desc' : 'asc';

            return ['column' => $default[0], 'direction' => $defaultDir];
        }

        return null;
    }
}
