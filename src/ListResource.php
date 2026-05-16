<?php

namespace Mercurio\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Page\Breadcrumb;
use Mercurio\Tables\Page\EmptyState;
use Mercurio\Tables\Page\HeaderAction;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\Table\TableBuilder;
use Mercurio\Tables\View\SavedView;

abstract class ListResource
{
    abstract public function key(): string;

    /**
     * Primary contract — источник данных для Resource'а.
     *
     * Реализации: {@see EloquentSource} (поверх Builder), а в будущих
     * фазах — ArraySource, SqlSource, HttpSource, FileSource.
     *
     * Если возвращает null, движок упадёт на legacy-{@see query()}.
     */
    public function source(): ?Source
    {
        return null;
    }

    /**
     * Legacy-контракт Eloquent\Builder. Автоматически оборачивается в
     * {@see EloquentSource} через {@see resolveSource()}.
     *
     * @deprecated Используйте {@see Source()}. Будет удалено в v3.
     *
     * @return Builder<Model>|null
     */
    public function query(): ?Builder
    {
        return null;
    }

    /**
     * Внутри пакета используется только этот метод (final).
     *
     * Резолвит Source: сначала {@see Source()}, потом legacy {@see query()}
     * через {@see EloquentSource}-shim с E_USER_DEPRECATED.
     */
    final public function resolveSource(): Source
    {
        $explicit = $this->source();
        if ($explicit !== null) {
            return $explicit;
        }

        $legacy = $this->query();
        if ($legacy !== null) {
            @trigger_error(
                sprintf(
                    "ListResource '%s'::query() is deprecated. Implement source() instead. Removal in v3.",
                    $this->key(),
                ),
                E_USER_DEPRECATED,
            );

            Log::info('tables.list_resource.query_shim_used', [
                'resource' => $this->key(),
            ]);

            return new EloquentSource($legacy, $this);
        }

        throw new \LogicException(
            "Resource '{$this->key()}' has no source() and no query(): both returned null.",
        );
    }

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
        $rowClass = is_object($row) ? $row::class : 'array';
        $visible = [];

        foreach ($this->rowActionsMemo() as $action) {
            if (! $action instanceof RowAction) {
                continue;
            }

            if ($action->isHiddenFor($row)) {
                continue;
            }

            $hasPolicy = $action->hasPolicy() || $action->getAbility() !== null;
            if (! $hasPolicy) {
                $visible[] = $action;

                continue;
            }

            if ($action->isSharedAuthz()) {
                $cacheKey = $action->name.'@'.$rowClass;
                $allowed = $this->rowAuthzCache[$cacheKey]
                    ??= $this->isActionAuthorized($action, $row, $actor);
                if ($allowed) {
                    $visible[] = $action;
                }

                continue;
            }

            if ($this->isActionAuthorized($action, $row, $actor)) {
                $visible[] = $action;
            }
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
        $declared = $this->bulkActionsMemo();
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
        return Auth::guard($this->effectiveGuard())->user();
    }

    /**
     * Override the auth guard for this Resource. Return null to use
     * config('tables.guard', 'web') (default). Useful when two Resources
     * with different guards (e.g. admin + storefront) coexist on one page.
     */
    public function guard(): ?string
    {
        return null;
    }

    /**
     * Effective guard for this Resource (override or config fallback).
     * Use in services/views instead of reading config('tables.guard') directly.
     */
    final public function effectiveGuard(): string
    {
        return $this->guard() ?? (string) config('tables.guard', 'web');
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
     * Type-based probe для bulk-actions: пустой instance объекта через {@see Source::probe()}.
     * Возвращает null при ошибке — UI fallback'ится на «показать всё», server-side проверит.
     */
    private function buildBulkPolicyProbe(): ?object
    {
        try {
            $probe = $this->resolveSource()->probe();

            return is_object($probe) ? $probe : null;
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

    /**
     * Если `false` — auto-registered route `PATCH {base}/cells/{id}/{field}`
     * не регистрируется в `Route::tablesPage()`/`Route::tablesResource()`,
     * и cellUpdate-эндпоинт становится недоступен (404). Полезно для
     * read-only ресурсов, где `editableUsing(...)` не используется ни на
     * одном поле.
     */
    public function cellEditEnabled(): bool
    {
        return true;
    }

    /** @var array<int, ?string> */
    private array $resolvedAuditActors = [];

    /** @var array<string, bool> */
    private array $rowAuthzCache = [];

    /** @var array<int, Field>|null */
    private ?array $cachedFields = null;

    /** @var array<int, SavedView>|null */
    private ?array $cachedSavedViews = null;

    /** @var array<int, BulkAction>|null */
    private ?array $cachedBulkActions = null;

    /** @var array<int, RowAction>|null */
    private ?array $cachedRowActions = null;

    /**
     * @return array<int, Field>
     */
    final public function fieldsMemo(): array
    {
        return $this->cachedFields ??= $this->fields();
    }

    /**
     * @return array<int, SavedView>
     */
    final public function savedViewsMemo(): array
    {
        return $this->cachedSavedViews ??= $this->savedViews();
    }

    /**
     * @return array<int, BulkAction>
     */
    final public function bulkActionsMemo(): array
    {
        return $this->cachedBulkActions ??= $this->bulkActions();
    }

    /**
     * @return array<int, RowAction>
     */
    final public function rowActionsMemo(): array
    {
        return $this->cachedRowActions ??= $this->rowActions();
    }

    public function resolveAuditActor(?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        if (array_key_exists($actorId, $this->resolvedAuditActors)) {
            return $this->resolvedAuditActors[$actorId];
        }

        $guard = $this->effectiveGuard();
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

    public function table(Request $request): ResourceTable
    {
        return app(TableBuilder::class)->build($this, $request);
    }

    /**
     * Resolve full export state (filtered Source с применённым Query, total count,
     * ordered visible columns, raw query params for async dispatch). Sort и
     * pagination намеренно не применяются — {@see Source::stream()} стримит
     * выборку по primary key (или по cursor для не-Eloquent sources).
     *
     * @return array{source: Source, total: int, columns: array<int, Field>, queryParams: array<string, mixed>}
     */
    public function exportState(Request $request): array
    {
        return app(TableBuilder::class)->buildForExport($this, $request);
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
        foreach ($this->fieldsMemo() as $field) {
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

    public function findField(string $name): ?Field
    {
        foreach ($this->fieldsMemo() as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
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
}
