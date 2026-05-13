<?php

namespace Mercurio\Tables\Table;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterPipeline;
use Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Prefs\UserPrefsResolver;
use Mercurio\Tables\ResourceTable;
use Mercurio\Tables\Services\SavedViewCountsCalculator;

/**
 * @internal
 *
 * Composes query + fields + savedViews + prefs + paginator into a finished ResourceTable.
 * Invoke via `app(TableBuilder::class)->build($resource, $request)`.
 */
final class TableBuilder
{
    public function __construct(
        private readonly FilterPipeline $filterPipeline,
        private readonly UserPrefsResolver $prefsResolver,
        private readonly SavedViewCountsCalculator $savedViewCounts,
    ) {}

    public function build(ListResource $resource, Request $request): ResourceTable
    {
        $query = $resource->query();
        $fields = $resource->fieldsMemo();
        $savedViews = $resource->savedViewsMemo();
        $savedViewCounts = $savedViews === []
            ? []
            : $this->savedViewCounts->counts($resource);

        $applied = $this->filterPipeline->apply($query, $request, $resource, $fields, $savedViews);

        $sort = SortResolver::resolve(
            $request->query('sort'),
            $request->query('dir'),
            $fields,
            $resource->defaultSort(),
        );
        if ($sort !== null) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        $prefs = $this->prefsResolver->resolve($resource, $request);
        $effectivePerPage = $prefs->perPage ?? $resource->perPage();
        $effectiveDensity = $prefs->density ?? $resource->density();
        $effectiveColumns = $prefs->columns;

        $paginator = $query
            ->paginate($effectivePerPage)
            ->withQueryString();

        $qbRoot = $applied['qbRoot'];
        $qbVo = $qbRoot !== null
            ? [
                'json' => json_encode(ListResource::astToArray($qbRoot), JSON_UNESCAPED_UNICODE),
                'atoms' => QueryBuilderNormalizer::countAtoms($qbRoot),
                'depth' => QueryBuilderNormalizer::maxDepth($qbRoot),
            ]
            : null;

        return new ResourceTable(
            key: $resource->key(),
            paginator: $paginator,
            fields: $fields,
            savedViews: $savedViews,
            bulkActions: $resource->resolveBulkActions(),
            rowActions: $resource->rowActionsMemo(),
            sort: $sort,
            currentView: $applied['currentView'],
            search: $applied['search'],
            density: $this->normalizeDensity($effectiveDensity),
            summary: $resource->summary(),
            resource: $resource,
            activeFilters: $applied['activeFilters'],
            qb: $qbVo,
            savedViewCounts: $savedViewCounts,
            effectiveColumns: $effectiveColumns,
            perPage: $effectivePerPage,
            emptyState: $resource->emptyState(),
        );
    }

    /**
     * @return array{builder: Builder<Model>, total: int, columns: array<int, Field>, queryParams: array<string, mixed>}
     */
    public function buildForExport(ListResource $resource, Request $request): array
    {
        $query = $resource->query();
        $fields = $resource->fieldsMemo();
        $savedViews = $resource->savedViewsMemo();

        $this->filterPipeline->apply($query, $request, $resource, $fields, $savedViews);

        $prefs = $this->prefsResolver->resolve($resource, $request);
        $effectiveColumnNames = $prefs->columns ?? array_values(array_map(
            fn (Field $f) => $f->name,
            array_filter($fields, fn (Field $f) => ! $f->isHidden() && ! $f->isOnlyFilterable()),
        ));

        $byName = [];
        foreach ($fields as $field) {
            if ($field->isOnlyFilterable()) {
                continue;
            }
            $byName[$field->name] = $field;
        }
        $columns = [];
        foreach ($effectiveColumnNames as $name) {
            if (isset($byName[$name])) {
                $columns[] = $byName[$name];
            }
        }

        $total = (int) (clone $query)->toBase()->getCountForPagination();

        return [
            'builder' => $query,
            'total' => $total,
            'columns' => $columns,
            'queryParams' => (array) $request->query(),
        ];
    }

    private function normalizeDensity(string $raw): string
    {
        return in_array($raw, ['compact', 'comfortable'], true) ? $raw : 'comfortable';
    }
}
