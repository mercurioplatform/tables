<?php

namespace Mercurio\Tables\Table;

use Illuminate\Http\Request;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterPipeline;
use Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Prefs\UserPrefsResolver;
use Mercurio\Tables\ResourceTable;
use Mercurio\Tables\Services\SavedViewCountsCalculator;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\View\SavedView;

/**
 * @internal
 *
 * Composes Source + fields + savedViews + prefs + Page into a finished ResourceTable.
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
        $source = $resource->resolveSource();
        $fields = $resource->fieldsMemo();
        $savedViews = $resource->savedViewsMemo();
        $savedViewCounts = $savedViews === []
            ? []
            : $this->savedViewCounts->counts($resource);

        $applied = $this->filterPipeline->build($request, $resource, $fields, $savedViews);
        $query = $applied['query'];

        $sort = SortResolver::resolve(
            $request->query('sort'),
            $request->query('dir'),
            $fields,
            $resource->defaultSort(),
        );
        if ($sort !== null) {
            $query->sortField = $sort['column'];
            $query->sortDirection = $sort['direction'];
        }

        $prefs = $this->prefsResolver->resolve($resource, $request);
        $effectivePerPage = $prefs->perPage ?? $resource->perPage();
        $effectiveDensity = $prefs->density ?? $resource->density();
        $effectiveColumns = $prefs->columns;

        $page = (int) $request->query('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $appliedSource = $source->withQuery($query);
        $appliedSource = $this->applySourceClosure(
            $appliedSource,
            $savedViews,
            $query->savedViewKey,
            $resource->key(),
            'tables.saved_view.source_closure_applied',
        );
        $resultPage = $appliedSource->page($page, $effectivePerPage);

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
            page: $resultPage,
            capabilities: $appliedSource->capabilities(),
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
     * @return array{source: Source, total: int, columns: array<int, Field>, queryParams: array<string, mixed>}
     */
    public function buildForExport(ListResource $resource, Request $request): array
    {
        $source = $resource->resolveSource();
        $fields = $resource->fieldsMemo();
        $savedViews = $resource->savedViewsMemo();

        $applied = $this->filterPipeline->build($request, $resource, $fields, $savedViews);
        $appliedSource = $source->withQuery($applied['query']);
        $appliedSource = $this->applySourceClosure(
            $appliedSource,
            $savedViews,
            $applied['query']->savedViewKey,
            $resource->key(),
            'tables.saved_view.source_closure_applied_export',
        );

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

        $total = $appliedSource->count() ?? 0;

        return [
            'source' => $appliedSource,
            'total' => $total,
            'columns' => $columns,
            'queryParams' => (array) $request->query(),
        ];
    }

    private function normalizeDensity(string $raw): string
    {
        return in_array($raw, ['compact', 'comfortable'], true) ? $raw : 'comfortable';
    }

    /**
     * Применяет `SavedView::sourceClosure(Closure(Source): Source)` поверх уже
     * прошедшего {@see Source::withQuery()} source-instance. Применяется и в
     * {@see self::build()}, и в {@see self::buildForExport()}, иначе export
     * для sourceClosure-saved-view отдал бы не-фильтрованный stream.
     *
     * Сигнатура `Source::withQuery(Query): static` НЕ меняется — closure
     * применяется снаружи, симметрично для рендера и для экспорта.
     *
     * @param  array<int, SavedView>  $savedViews
     */
    private function applySourceClosure(
        Source $source,
        array $savedViews,
        ?string $savedViewKey,
        string $resourceKey,
        string $logKey,
    ): Source {
        if ($savedViewKey === null) {
            return $source;
        }

        foreach ($savedViews as $sv) {
            if ($sv->key !== $savedViewKey || $sv->sourceClosure === null) {
                continue;
            }

            $source = ($sv->sourceClosure)($source);

            break;
        }

        return $source;
    }
}
