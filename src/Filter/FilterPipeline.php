<?php

namespace Mercurio\Tables\Filter;

use Illuminate\Http\Request;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\View\SavedView;

/**
 * @internal
 *
 * Парсит request-параметры (q, view, f, qb) и собирает neutral {@see Query} VO.
 * Не мутирует Eloquent\Builder — применение Query к источнику делает сам
 * Source-драйвер (см. {@see EloquentSource::withQuery()}).
 */
final class FilterPipeline
{
    /**
     * @param  array<int, Field>  $fields
     * @param  array<int, SavedView>  $savedViews
     * @return array{
     *     query: Query,
     *     search: string|null,
     *     currentView: string|null,
     *     conditions: array<int, FilterCondition>,
     *     activeFilters: array<string, FilterCondition>,
     *     qbRoot: AtomCondition|AtomGroup|null,
     * }
     */
    public function build(
        Request $request,
        ListResource $resource,
        array $fields,
        array $savedViews,
    ): array {
        $query = new Query;
        $query->searchableColumns = $resource->searchable();

        $search = $this->normalizeSearch($request->query('q'));
        $query->search = $search;

        $currentView = $this->resolveCurrentView($request->query('view'), $savedViews);
        $query->savedViewKey = $currentView;

        $rawFilters = $request->input('f', []);
        $conditions = is_array($rawFilters)
            ? FilterParser::parse($rawFilters, $resource)
            : [];

        $activeFilters = [];
        foreach ($conditions as $cond) {
            if ($this->fieldByName($cond->field, $fields) !== null) {
                $activeFilters[$cond->field] = $cond;
            }
        }

        $svConditions = [];
        if ($currentView !== null) {
            foreach ($savedViews as $sv) {
                if ($sv->key === $currentView && $sv->conditions !== []) {
                    $svConditions = $sv->conditions;
                    break;
                }
            }
        }
        $query->conditions = [...$svConditions, ...$conditions];

        $rawQb = $request->query('qb');
        $qbRoot = is_string($rawQb) && $rawQb !== ''
            ? QueryBuilderParser::parse($rawQb, $resource)
            : null;
        if ($qbRoot !== null) {
            $qbRoot = QueryBuilderNormalizer::normalize($qbRoot);
        }
        $query->qbRoot = $qbRoot;

        return [
            'query' => $query,
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
