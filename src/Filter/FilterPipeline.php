<?php

namespace Mercurio\Tables\Filter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\QueryBuilderApplier;
use Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\View\SavedView;

/**
 * @internal
 *
 * Composes search + saved-view + chip-filters + qb-AST for a single request.
 * Stateless service: all state lives in the arguments passed in.
 */
final class FilterPipeline
{
    /**
     * @param  Builder<Model>  $query
     * @param  array<int, Field>  $fields
     * @param  array<int, SavedView>  $savedViews
     * @return array{
     *     search: string|null,
     *     currentView: string|null,
     *     conditions: array<int, FilterCondition>,
     *     activeFilters: array<string, FilterCondition>,
     *     qbRoot: AtomCondition|AtomGroup|null,
     * }
     */
    public function apply(
        Builder $query,
        Request $request,
        ListResource $resource,
        array $fields,
        array $savedViews,
    ): array {
        $search = $this->normalizeSearch($request->query('q'));
        if ($search !== null) {
            $this->applySearch($query, $search, $resource->searchable());
        }

        $currentView = $this->resolveCurrentView($request->query('view'), $savedViews);
        if ($currentView !== null) {
            $this->findView($currentView, $savedViews)?->apply($query);
        }

        $rawFilters = $request->input('f', []);
        $conditions = is_array($rawFilters)
            ? FilterParser::parse($rawFilters, $resource)
            : [];

        $activeFilters = [];
        foreach ($conditions as $cond) {
            $field = $this->fieldByName($cond->field, $fields);
            if ($field !== null) {
                FilterApplier::apply($query, $field, $cond);
                $activeFilters[$cond->field] = $cond;
            }
        }

        $rawQb = $request->query('qb');
        $qbRoot = is_string($rawQb) && $rawQb !== ''
            ? QueryBuilderParser::parse($rawQb, $resource)
            : null;
        if ($qbRoot !== null) {
            $qbRoot = QueryBuilderNormalizer::normalize($qbRoot);
        }
        if ($qbRoot !== null) {
            $query->where(function (Builder $sub) use ($qbRoot, $resource): void {
                QueryBuilderApplier::apply($sub, $qbRoot, $resource);
            });
        }

        return [
            'search' => $search,
            'currentView' => $currentView,
            'conditions' => $conditions,
            'activeFilters' => $activeFilters,
            'qbRoot' => $qbRoot,
        ];
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

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $columns
     */
    private function applySearch(Builder $query, string $term, array $columns): void
    {
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

    /** @param  array<int, SavedView>  $savedViews */
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

    /** @param  array<int, SavedView>  $savedViews */
    private function findView(string $key, array $savedViews): ?SavedView
    {
        foreach ($savedViews as $view) {
            if ($view->key === $key) {
                return $view;
            }
        }

        return null;
    }

    /** @param  array<int, Field>  $fields */
    private function fieldByName(string $name, array $fields): ?Field
    {
        foreach ($fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
