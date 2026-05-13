<?php

namespace Mercurio\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\FilterParser;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\QueryBuilderApplier;
use Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use Mercurio\Tables\Page\Breadcrumb;
use Mercurio\Tables\Page\EmptyState;
use Mercurio\Tables\Page\HeaderAction;
use Mercurio\Tables\Prefs\UserPrefsResolver;
use Mercurio\Tables\Services\SavedViewCountsCalculator;
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
     * @return array<int, RowAction>
     */
    public function rowActions(): array
    {
        return [];
    }

    /**
     * Per-row visibility filter for declared rowActions().
     *
     * @return array<int, RowAction>
     */
    public function resolveRowActions(mixed $row): array
    {
        $actor = $this->currentActor();
        $visible = [];

        foreach ($this->rowActions() as $action) {
            if (! $action instanceof RowAction) {
                continue;
            }

            if ($action->isHiddenFor($row)) {
                continue;
            }

            if (! $this->isActionAuthorized($action, $row, $actor)) {
                continue;
            }

            $visible[] = $action;
        }

        return $visible;
    }

    /**
     * Bulk-actions, отфильтрованные для текущего actor'а через type-based probe (новый instance модели).
     * Open-actions (без policy/ability) проходят без проверки.
     *
     * @return array<int, BulkAction>
     */
    public function resolveBulkActions(): array
    {
        $declared = $this->bulkActions();
        if ($declared === []) {
            return [];
        }

        $actor = $this->currentActor();
        $probe = null;
        $probeBuilt = false;
        $visible = [];

        foreach ($declared as $action) {
            if (! $action instanceof BulkAction) {
                continue;
            }

            if (! $action->hasPolicy() && $action->getAbility() === null) {
                $visible[] = $action;

                continue;
            }

            if (! $probeBuilt) {
                $probe = $this->buildBulkPolicyProbe();
                $probeBuilt = true;
            }

            if ($probe === null) {
                // Не смогли построить probe — server-side всё равно валидирует, не скрываем.
                $visible[] = $action;

                continue;
            }

            if ($this->isActionAuthorized($action, $probe, $actor)) {
                $visible[] = $action;
            }
        }

        return $visible;
    }

    protected function currentActor(): ?Authenticatable
    {
        $guard = (string) config('tables.guard', 'web');

        return Auth::guard($guard)->user();
    }

    private function isActionAuthorized(BulkAction|RowAction $action, mixed $subject, ?Authenticatable $actor): bool
    {
        $policy = $action->getPolicy();
        if ($policy !== null) {
            return (bool) Gate::forUser($actor)->check($policy['method'], $subject);
        }

        $ability = $action->getAbility();
        if ($ability !== null) {
            return (bool) Gate::forUser($actor)->check($ability, $subject);
        }

        return true;
    }

    /**
     * Type-based probe для bulk-actions: пустой instance модели через query()->getModel()->newInstance().
     * Возвращает null при ошибке — UI fallback'ится на «показать всё», server-side проверит.
     */
    private function buildBulkPolicyProbe(): ?object
    {
        try {
            $model = $this->query()->getModel();

            return $model->newInstance();
        } catch (\Throwable $e) {
            Log::warning('tables.policy.probe_build_failed', [
                'resource' => $this->key(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Optional override for row-action route name base (e.g. `admin.catalog.products.v2`).
     * When null, the engine derives it from `Route::currentRouteName()`.
     */
    public function routeBaseName(): ?string
    {
        return null;
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

    public function emptyState(): ?EmptyState
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function filterGroupLabels(): array
    {
        return [];
    }

    public function filterGroupThreshold(): int
    {
        return 10;
    }

    public function pageTitle(): ?string
    {
        return null;
    }

    public function browserTitle(): ?string
    {
        return $this->pageTitle();
    }

    public function subtitle(int $total): ?string
    {
        return null;
    }

    /**
     * @return array<int, HeaderAction>
     */
    public function headerActions(): array
    {
        return [];
    }

    public function actionHistoryEnabled(): bool
    {
        return false;
    }

    /** @var array<int, ?string> */
    private array $resolvedAuditActors = [];

    public function resolveAuditActor(?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        if (array_key_exists($actorId, $this->resolvedAuditActors)) {
            return $this->resolvedAuditActors[$actorId];
        }

        $guard = (string) config('tables.guard', 'web');
        $providerName = config("auth.guards.{$guard}.provider");

        if (! is_string($providerName) || $providerName === '') {
            Log::warning('tables.audit_actor.guard_provider_missing', [
                'resource' => $this->key(),
                'guard' => $guard,
            ]);

            return $this->resolvedAuditActors[$actorId] = null;
        }

        try {
            $provider = Auth::createUserProvider($providerName);
        } catch (\InvalidArgumentException $e) {
            Log::warning('tables.audit_actor.provider_driver_invalid', [
                'resource' => $this->key(),
                'guard' => $guard,
                'provider' => $providerName,
                'reason' => $e->getMessage(),
            ]);

            return $this->resolvedAuditActors[$actorId] = null;
        }

        if ($provider === null) {
            Log::warning('tables.audit_actor.provider_not_resolvable', [
                'resource' => $this->key(),
                'guard' => $guard,
                'provider' => $providerName,
            ]);

            return $this->resolvedAuditActors[$actorId] = null;
        }

        $user = $provider->retrieveById($actorId);

        return $this->resolvedAuditActors[$actorId] = $this->formatAuditActor($user);
    }

    /**
     * Format display name for an audit actor. Override for non-standard schemas
     * (first_name + last_name, display_name, locale-aware formatting).
     */
    protected function formatAuditActor(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = data_get($user, 'name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        $email = data_get($user, 'email');
        if (is_string($email) && $email !== '') {
            return $email;
        }

        return null;
    }

    /**
     * @return array<int, Breadcrumb>
     */
    public function breadcrumbs(): array
    {
        return [];
    }

    public function layout(): string
    {
        return (string) config('tables.shell.layout', 'admin.layouts.app');
    }

    /**
     * @return array<string, string>
     */
    public function flashKeys(): array
    {
        return (array) config('tables.shell.flash_keys', [
            'status' => 'success',
            'warning' => 'warning',
            'error' => 'danger',
        ]);
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
        $savedViewCounts = $savedViews === []
            ? []
            : app(SavedViewCountsCalculator::class)->counts($this);

        $applied = $this->applyFiltersToQuery($query, $request, $fields, $savedViews);
        $search = $applied['search'];
        $currentView = $applied['currentView'];
        $conditions = $applied['conditions'];
        $activeFilters = $applied['activeFilters'];
        $qbRoot = $applied['qbRoot'];

        $sort = $this->resolveSort(
            $request->query('sort'),
            $request->query('dir'),
            $fields,
        );
        if ($sort !== null) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        $prefs = app(UserPrefsResolver::class)->resolve($this, $request);
        $effectivePerPage = $prefs->perPage ?? $this->perPage();
        $effectiveDensity = $prefs->density ?? $this->density();
        $effectiveColumns = $prefs->columns;

        $paginator = $query
            ->paginate($effectivePerPage)
            ->withQueryString();

        $summary = $this->summary();
        $emptyState = $this->emptyState();

        $qbVo = $qbRoot !== null
            ? [
                'json' => json_encode(self::astToArray($qbRoot), JSON_UNESCAPED_UNICODE),
                'atoms' => QueryBuilderNormalizer::countAtoms($qbRoot),
                'depth' => QueryBuilderNormalizer::maxDepth($qbRoot),
            ]
            : null;

        return new ResourceTable(
            key: $this->key(),
            paginator: $paginator,
            fields: $fields,
            savedViews: $savedViews,
            bulkActions: $this->resolveBulkActions(),
            rowActions: $this->rowActions(),
            sort: $sort,
            currentView: $currentView,
            search: $search,
            density: $this->normalizeDensity($effectiveDensity),
            summary: $summary,
            resource: $this,
            activeFilters: $activeFilters,
            qb: $qbVo,
            savedViewCounts: $savedViewCounts,
            effectiveColumns: $effectiveColumns,
            perPage: $effectivePerPage,
            emptyState: $emptyState,
        );
    }

    /**
     * Apply search + saved view + chip filters + qb to the given query.
     * Sort/pagination are intentionally out of scope (caller decides).
     *
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
    private function applyFiltersToQuery(Builder $query, Request $request, array $fields, array $savedViews): array
    {
        $search = $this->normalizeSearch($request->query('q'));
        if ($search !== null) {
            $this->applySearch($query, $search);
        }

        $currentView = $this->resolveCurrentView($request->query('view'), $savedViews);
        if ($currentView !== null) {
            $this->findView($currentView, $savedViews)?->apply($query);
        }

        $rawFilters = $request->input('f', []);
        $conditions = is_array($rawFilters)
            ? FilterParser::parse($rawFilters, $this)
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
            ? QueryBuilderParser::parse($rawQb, $this)
            : null;
        if ($qbRoot !== null) {
            $qbRoot = QueryBuilderNormalizer::normalize($qbRoot);
        }
        if ($qbRoot !== null) {
            $query->where(function (Builder $sub) use ($qbRoot): void {
                QueryBuilderApplier::apply($sub, $qbRoot, $this);
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

    /**
     * Resolve full export state (filtered builder, total count, ordered visible columns,
     * raw query params for async dispatch). Sort and pagination are intentionally not applied —
     * `chunkById` orders by PK and chunking is decided by the caller.
     *
     * @return array{builder: Builder, total: int, columns: array<int, Field>, queryParams: array<string, mixed>}
     */
    public function exportState(Request $request): array
    {
        $query = $this->query();
        $fields = $this->fields();
        $savedViews = $this->savedViews();

        $this->applyFiltersToQuery($query, $request, $fields, $savedViews);

        $prefs = app(UserPrefsResolver::class)->resolve($this, $request);
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

    /**
     * @return array<string, mixed>
     */
    public static function astToArray(AtomCondition|AtomGroup $node): array
    {
        if ($node instanceof AtomCondition) {
            return [
                'type' => 'cond',
                'field' => $node->field,
                'operator' => $node->operator->value,
                'value' => $node->value,
                'not' => $node->not,
            ];
        }

        return [
            'type' => 'group',
            'op' => $node->op,
            'not' => $node->not,
            'children' => array_map(fn ($c) => self::astToArray($c), $node->children),
        ];
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>}
     */
    public function qbSchema(): array
    {
        $fields = [];
        foreach ($this->fields() as $field) {
            if (! $field->isFilterable()) {
                continue;
            }
            $ops = $field->getFilterableOperators();
            if ($ops === []) {
                continue;
            }
            $entry = [
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->getQbValueType(),
                'operators' => array_map(fn (Operator $op) => $op->value, $ops),
                'multiple' => $field->isFilterMultiple(),
            ];
            if ($field->isFilterAutocomplete()) {
                $current = Route::currentRouteName();
                $base = is_string($current) && $current !== ''
                    ? preg_replace('/\.[^.]+$/', '', $current)
                    : null;
                $entry['optionsUrl'] = $base
                    ? route($base.'.options')
                    : url()->current().'/options';
            }
            $options = $field->getQbOptions();
            if ($options !== null) {
                $entry['options'] = $options;
            }
            $fields[] = $entry;
        }

        return ['fields' => $fields];
    }

    /**
     * @param  array<int, Field>  $fields
     */
    private function fieldByName(string $name, array $fields): ?Field
    {
        foreach ($fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function findField(string $name): ?Field
    {
        return $this->fieldByName($name, $this->fields());
    }

    /**
     * @param  array<int, int|string>  $selectedIds
     * @return array<int|string, string>
     */
    public function filterOptions(string $fieldName, ?string $q, Request $request, array $selectedIds = []): array
    {
        $field = $this->findField($fieldName);
        if ($field === null || ! $field->isFilterable() || ! $field->isFilterAutocomplete()) {
            return [];
        }

        return $field->resolveFilterOptions($q, $request, $selectedIds);
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
