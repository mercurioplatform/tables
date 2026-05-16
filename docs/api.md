# Public API matrix — mercurioplatform/tables v0.1.0

> **Стабильность**: всё, что перечислено в разделе **Stable API**, не меняется breaking-способом без major-bump (v0.x → v1.0 → v2.0). Расширения (новые методы, новые подклассы, новые конфиги, новые слоты) допустимы в minor (v0.1 → v0.2). Bug-fixes и improvements внутри internal — patch (v0.1.0 → v0.1.1).
>
> **Internal symbols** (раздел внизу) могут меняться в minor/patch без deprecation cycle. Использовать на свой риск; пакет не гарантирует совместимость.

## Stable API

### Resource & lifecycle

#### `Mercurio\Tables\ListResource` (abstract)

Override-points (фиксируются как часть контракта; добавление новых методов — non-breaking):

- `key(): string` — уникальный ID ресурса (в URL routing + saved views FK + audit log).
- `source(): ?\Mercurio\Tables\Source\Source` — primary contract источника данных (см. ниже «Advanced: Source contract»). По умолчанию `null` — движок упадёт на legacy-`query()`.
- `query(): ?\Illuminate\Database\Eloquent\Builder` — **deprecated с v2, удаление в v3.** Старый Eloquent-Builder-контракт; engine оборачивает его в `Mercurio\Tables\Source\EloquentSource` через `resolveSource()` и пишет `E_USER_DEPRECATED` + info-лог `tables.list_resource.query_shim_used`. Host-resource'ы из v1.x продолжают работать без правок; для миграции реализуйте `source(): ?Source`.
- `fields(): array<int, Field>`
- `searchable(): array<int, string>` — список колонок (включая dot-notation `relation.column`) для LIKE-поиска по `?q=...`.
- `savedViews(): array<int, SavedView>`
- `bulkActions(): array<int, BulkAction>`
- `rowActions(): array<int, RowAction>`
- `summary(): ?Summary`
- `perPage(): int`
- `density(): string` — default density (`'compact'` | `'comfortable'`); UserPrefs override'ит.
- `defaultSort(): array{0: string, 1: string}|null` — pair `[column, 'asc'|'desc']`; engine приводит `dir` к `'asc'`/`'desc'`.
- `routeBaseName(): ?string`
- `layout(): string` — Blade-layout, который `@extends`'ит `tables::shell`; default — `config('tables.shell.layout')`.
- `guard(): ?string` — override auth guard для этого Resource. `null` (default) = `config('tables.guard', 'web')`. Используется engine'ом для policy/Gate checks, user prefs, saved views, экспорта, audit log. Позволяет одной странице держать Resource'ы с разными guard'ами (например, админский `admin` + сторфронтовый `web`). Эффективное значение читается через `final effectiveGuard(): string` — используйте его в host-коде и кастомных интеграциях вместо прямого чтения `config('tables.guard')`.
- `pageTitle(): ?string`
- `browserTitle(): ?string`
- `subtitle(int $total): ?string`
- `breadcrumbs(): array<int, Breadcrumb>`
- `headerActions(): array<int, HeaderAction>`
- `emptyState(): ?EmptyState`
- `flashKeys(): array<string, string>` — map session-key → bootstrap alert variant.
- `filterGroupLabels(): array<string, string>` — кастомные label'ы для аккордеон-группировки филтр-чипов; ключ — значение `Field::filterGroup(...)`.
- `filterGroupThreshold(): int` — порог количества filterable-полей, выше которого включается аккордеон-группировка (default `10`).
- `actionHistoryEnabled(): bool`
- `cellEditEnabled(): bool` — default `true`. Если `false`, route `PATCH {base}/cells/{id}/{field}` не регистрируется (404), вне зависимости от наличия полей с `editable()`/`editableUsing()`. Probe вызывается один раз в boot-time из `Route::tablesPage(...)`; любая ошибка резолва Resource'а трактуется как fallback `true` (запись `Log::warning` `tables.routing.cell_edit_probe_failed`). Для `Route::tablesResource(...)` (host-controller) probe не выполняется и route регистрируется всегда.
- `resolveAuditActor(?int $actorId): ?string` — резолвит `actor_id` из `tables_action_log` в человекочитаемое имя для offcanvas «История». Default: `Auth::createUserProvider(config("auth.guards.{$this->effectiveGuard()}.provider"))->retrieveById($actorId)`, форматирование — через protected `formatAuditActor(?Authenticatable $user): ?string` (default `name ?? email ?? null`). Override `resolveAuditActor` целиком — для мульти-источников / soft-deleted / нестандартного lookup'а; override `formatAuditActor` — только для смены формата отображаемого имени.

Invariants:

- `key()` стабилен по жизни приложения (используется как FK в `tables_saved_views.resource_key` и audit log).
- `guard()` стабилен по жизни Resource'а (читается на каждый HTTP-action; смена в runtime не поддерживается).
- Любой переопределённый метод вызывается **до** AJAX/HTTP-обработки; side-effects (запросы к БД, IO) допустимы только в `query()` и `summary()`.

#### `Mercurio\Tables\ResourceTable`

Runtime VO собранной таблицы. Передаётся в `view('tables::shell', ['table' => $table, ...])` и в blade-компоненты `<x-tables.page :table="$table">` / `<x-tables.table-root :table="$table">`. **Не инстанцировать вручную** — собирается через `ListResource::table($request)`.

Public `readonly` properties: `key`, `fields`, `page`, `capabilities`, `savedViews`, `bulkActions`, `rowActions`, `sort`, `currentView`, `search`, `density`, `summary`, `resource`, `activeFilters`, `qb`, `savedViewCounts`, `effectiveColumns`, `perPage`, `emptyState`.

Legacy property `paginator` (LengthAwarePaginator) — **deprecated с v2, удаление в v3.** Чтение `$table->paginator` продолжает работать через `__get`-proxy: возвращается `$table->page` (Page реализует LengthAwarePaginator-compatible shim для `total/hasPages/onFirstPage/previousPageUrl/currentPage/hasMorePages/nextPageUrl/onEachSide/links/items/firstItem/lastItem/perPage`). Первое обращение пишет DEBUG-лог `tables.resource_table.paginator_legacy_access` один раз за process lifetime. Замените на `$table->page`.

Public методы:

- `rows(): \Illuminate\Support\Collection` — items текущей страницы (`$table->page->rows`).
- `visibleFields(): array<int, Field>` — поля для рендера (с учётом UserPrefs).
- `filterableFields(): array<int, Field>`
- `groupedFilterableFields(): ?array` — группировка filter-bar когда `count > filterGroupThreshold()`.
- `availablePrefsColumns(): array<int, array{name: string, label: string, hidden_by_default: bool}>`
- `effectiveColumnNames(): array<int, string>`
- `effectivePerPage(): int`
- `shellHeaderActions(): array<int, HeaderAction>` — `headerActions()` + auto-injected history-action когда `actionHistoryEnabled()`.
- `actionLogOffcanvasId(): string`
- Предикаты: `hasRowActions()`, `hasAnyEditableFields()`, `hasActiveFilters()`, `hasRowActionForms()`, `hasBulkActionForms()`, `hasConfirmPreviews()`.

Поле `total` отдельным accessor'ом не предоставляется — использовать `$table->paginator->total()`.

#### `Mercurio\Tables\ResourceRegistry`

DI-singleton. Публичные методы:

- `register(string $resourceClass): void` — добавляет FQCN ListResource в реестр (idempotent). Используется `tables.resources` config-ключом и user-кодом для явного bind вне роута.
- `all(): array<int, ListResource>` — инстанцирует все зарегистрированные ListResource через `app($class)`. Используется `SystemViewSyncer` и `tables:sync-saved-views`.
- `classes(): array<int, class-string>` — без инстанцирования (для лёгких lookup'ов).

Lookup'а «по `key()`» в реестре нет — пользовательский код, которому нужен ListResource по `key`, делает linear scan через `all()` либо хранит свою map.

#### `Mercurio\Tables\Concerns\HandlesResourceListing` (controller trait)

Публичные HTTP-action методы (signature не меняем):

| Method | HTTP role |
|---|---|
| `index(\Illuminate\Http\Request $request)` | список + AJAX-partial |
| `bulkAction(\Illuminate\Http\Request $request)` | execute bulk handler |
| `bulkActionForm(\Illuminate\Http\Request $request, string $action)` | form schema render |
| `bulkActionPreview(\Illuminate\Http\Request $request, string $action)` | confirm-preview для bulk action (`BulkAction::preview(\Closure)`) |
| `rowAction(\Illuminate\Http\Request $request, $id, string $action)` | execute row handler |
| `rowActionForm(\Illuminate\Http\Request $request, $id, string $action)` | form schema render |
| `rowActionPreview(\Illuminate\Http\Request $request, $id, string $action)` | confirm-preview для row action |
| `actionLog(\Illuminate\Http\Request $request)` | offcanvas history |
| `actionLogUndo(\Illuminate\Http\Request $request, int $logId)` | undo entry |
| `savePrefs(\Illuminate\Http\Request $request)` | save user prefs (visible columns / density / page size) |
| `resetPrefs(\Illuminate\Http\Request $request)` | reset user prefs до Resource-defaults |
| `saveView(\Illuminate\Http\Request $request)` | create / update / setDefault user saved view (поле `action` в payload) |
| `deleteUserView(\Illuminate\Http\Request $request, int $id)` | delete user saved view |
| `export(\Illuminate\Http\Request $request)` | CSV stream / async dispatch |
| `cellUpdate(\Illuminate\Http\Request $request, $id, string $field)` | inline cell update (PATCH `{base}/cells/{id}/{field}`). Route регистрируется только если `ListResource::cellEditEnabled()` вернул `true` (default). Для read-only ресурсов переопределить на `false` — эндпоинт станет 404. |
| `actionProgress(\Illuminate\Http\Request $request, string $progress)` | bulk-progress polling |
| `options(\Illuminate\Http\Request $request)` | autocomplete для BelongsToField |

Trait требует `protected string $resource = ResourceClass::class`. Внутренняя реализация делегирует на helper/handler-классы (`@internal`, см. ниже).

#### `Route` macros

- `Route::tablesResource(string $path, string $controller): PendingTablesResource` — escape hatch для кастомных контроллеров с `HandlesResourceListing`.
- `Route::tablesPage(string $path, string $resourceClass): PendingTablesResource` — декларативный путь без контроллера (использует встроенный internal `GenericTablesController`).

`PendingTablesResource` — fluent builder; chain-методы `->name(string $base)`, `->middleware(array|string)`, `->where(array<string, string>)` применяются на все ~17 зарегистрированных Route. **Билдер internal** (см. internal-секцию), но макросы — public.

### Fields

#### `Mercurio\Tables\Field\Field` (abstract)

Fluent DSL. Сигнатуры заморожены:

```php
Field::make(string $name, ?string $label = null): static
->sortable(bool $value = true): static
->align(string $align): static  // 'left' | 'right' | 'center' — hint, runtime принимает любой string
->mono(bool $value = true): static
->cellView(string $bladePath): static
->displayUsing(\Closure $fn): static  // ($value, $row): mixed
->subline(\Closure $fn): static  // ($value, $row): ?string
->linkTo(\Closure $fn): static  // ($value, $row): ?string (URL)
->hideByDefault(bool $value = true): static
->editableUsing(
    ?array $policy = null,             // ['class' => PolicyClass::class, 'method' => 'name']
    array|\Closure|null $rules = null, // array<int, mixed> или Closure(?Model): array
    ?\Closure $transform = null,       // Closure(mixed $value, Model $model): mixed — после валидации, до update()
    ?string $column = null,            // целевая колонка БД (default = $name)
    array|\Closure|null $options = null, // для select-инпута: array|Closure(): array
): static  // primary cell-edit API — устанавливает enabled=true и мерджит spec
->editable(bool $value = true): static  // shortcut for editableUsing(...)
->editColumn(string $column): static  // shortcut for editableUsing(column: ...)
->editOptions(\Closure|array $options): static  // shortcut for editableUsing(options: ...)
->editRules(array|\Closure $rules): static  // shortcut for editableUsing(rules: ...)
->editPolicy(string $policyClass, string $method): static  // shortcut for editableUsing(policy: ...)
->filterable(array $operators = []): static
->filterOptions(\Closure|array $optionsOrFn): static
->onlyFilterable(bool $value = true): static
->filterUsing(\Closure $fn): static  // ($builder, FilterCondition): void
->filterScope(string $modelScopeName): static
->filterGroup(string $key): static  // ключ для аккордеон-группировки фильтров (ListResource::filterGroupLabels())
->filterPopover(string $type): static  // override popover-типа: 'text' | 'autocomplete' | ...
->filterAutocomplete(bool $value = true): static  // включить autocomplete-popover (использует /options endpoint)
```

Конкретные подклассы (без поломок сигнатур):

`TextField`, `TwoLineField`, `NumberField`, `MoneyField`, `DiscountedMoneyField`, `BooleanField`, `StatusField`, `BadgesField`, `ImageField`, `AvatarField`, `BelongsToField`, `BelongsToManyField`, `RelationCountField`, `RatingField`, `ProgressBarField`, `ConditionalColorField`, `JsonField`, `TagsField`, `DateField`.

Расширение через подкласс — допустимо; новые конкретные подклассы добавляются в minor.

##### Cell-edit pipeline

`editableUsing(...)` — единая точка конфигурации inline-edit. Все 5 shortcut-методов (`editable`, `editColumn`, `editPolicy`, `editRules`, `editOptions`) мерджат в тот же internal VO `CellEditSpec`.

Pipeline для `PATCH {base}/cells/{id}/{field}`:

1. `policy` — `Gate::forUser($actor)->check($policy['method'], $model)` → `403` при отказе.
2. `rules` — `Validator::make(['value' => $request->input('value')], ['value' => $rules])` → `422` при провале.
3. `transform` — если задан, вызывается `$transform($validatedValue, $model)` после валидации, до записи. Исключение → `Log::error('tables.cell_edit.transform_failed', ...)` + `422` с message из `tables::cell_edit.transform_failed`.
4. `$model->update([$column => $value])` внутри `DB::transaction(...)`.

Пример с `transform` (нормализация перед записью):

```php
TextField::make('slug')
    ->editableUsing(
        policy: [ProductPolicy::class, 'edit'],
        rules: ['required', 'string', 'max:255'],
        transform: fn (string $value) => trim(strtolower($value)),
    );
```

### Form fields (для `BulkAction::schema()` / `RowAction::schema()`)

`Mercurio\Tables\Form\Field\FormField` (abstract) + `FieldRow`.

Конкретные:

- `TextField` (alias hint: импортировать как `TextInput` если конфликтует с `Field\TextField`).
- `NumberField` (alias hint: `NumberInput`).
- `TextareaField`, `SelectField`, `RadioGroupField`, `CheckboxField`, `PlaintextField`.

`SelectField` factory-методы:

- `SelectField::options(string $name, string $label, array<int|string, string> $options)` — статический список options.
- `SelectField::enum(string $name, string $label, string $enumClass)` — backed-enum; если enum имплементирует `label(): string` — используется как display, иначе `case->name`.
- `SelectField::relation(string $name, string $label, \Closure $resolver)` — `$resolver(): array<int|string, string>` строит список options (например, через `Model::pluck('name', 'id')`).

`SelectField` дополнительно: `empty(?string $label = '— не выбрано —'): self` — добавляет «пустой» option.

Fluent (общий для всех form-fields, наследуется от `FormField`):

- `required(bool $flag = true): static` — auto-prepend `'required'` правила в `compileRules()`.
- `rules(array<int, mixed> $rules): static`
- `messages(array<string, string> $messages): static`
- `value(mixed $default): static` — статическое default-значение.
- `valueFrom(\Closure $resolver): static` — `$resolver($model): mixed`; используется в form-render когда форма привязана к конкретной row (row-action edit).
- `helper(?string $text): static`
- `attrs(array<string, string> $attrs): static` — HTML-атрибуты для input'а (merge).
- `attribute(string $name): static` — кастомный «human-readable» attribute name для validator-сообщений.

### Actions

#### `Mercurio\Tables\Action\Action` (interface)

Контракт для handler-классов, передаваемых в `BulkAction::handler(string $actionClass)` / `RowAction::handler(string $actionClass)`. Единственный метод:

- `execute(mixed $subject, array $payload): ActionResult`

`BulkAction` и `RowAction` НЕ имплементируют этот интерфейс — у них нет общего предка-класса. Их публичные fluent-методы описаны независимо в соответствующих разделах ниже.

#### `Mercurio\Tables\Action\BulkAction`

Создание + kind:

```php
BulkAction::make(string $name, string $label): self
->kind(string $kind): self  // 'instant' | 'confirm' | 'form' — обычно ставится автоматически instant()/confirm()/form()
->instant(): self
->confirm(?string $text = null): self  // переключает kind в 'confirm', опционально задаёт confirmText
->confirmText(string $text): self
->form(?string $formRequest = null, ?string $slot = null): self  // переключает kind в 'form'
->schema(array<int, FormField|FieldRow> $schema): self  // также переключает kind в 'form'
->formRequest(string $class): self
->slot(string $name): self
```

Handler / inline-callback:

```php
->handler(string $actionClass): self  // FQCN of Action implementor
->using(\Closure $callback): self  // ($subjectIds, $payload, ?$actor): ActionResult
->payload(array<string, mixed> $payload): self  // статический payload, добавляется поверх request-input
```

Authorization:

```php
->ability(string $name): self
->policy(string $policyClass, string $method): self
```

UI / отображение:

```php
->icon(string $icon): self
->variant(string $variant): self  // hint: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' | 'default' (рантайм — любой string)
->tooltip(string $text): self
->reloadAfterSubmit(bool $reload = true): self
```

Hook'и валидации:

```php
->prepareInput(\Closure $fn): self  // (array $input): array
->withValidator(\Closure $fn): self  // (Validator $v, array $data): void
->transformValidated(\Closure $fn): self  // (array $validated): array
```

Preview / undo / queue:

```php
->preview(\Closure $callback): self
  // ($subjectIds, $payload): View|string|array — рендерится в offcanvas вместо native confirm
  // применимо только при kind === 'confirm'

->undoable(\Closure $capture, \Closure $reverse): self
  // $capture($subjectIds, $payload, ListResource): array<string, mixed> — snapshot ДО операции
  // $reverse($captured, $payload, ?$actor): ActionResult — откат по snapshot'у

->queue(?string $queueName = null): self
->queueChunkSize(int $size): self
->queueWhen(int $threshold): self  // порог количества subjects, выше которого action идёт в очередь

->onSuccess(\Closure $cb): self  // (ActionResult): string|array — кастомный flash
->onError(\Closure $cb): self    // (\Throwable): string|array — кастомный flash при ошибке
```

**Mutex** (declaration-time guard, throws `\InvalidArgumentException`):

- `queue()` ↔ `undoable()` — capture-snapshot не снимается в queued-path'е.
- `queue()` ↔ `using()` — closure не сериализуется в очередь. Для queue-aware action — `handler(Class)`.

#### `Mercurio\Tables\Action\RowAction`

Создание (две factory-точки):

```php
RowAction::make(string $name, string $label): self
RowAction::link(string $name, string $label, \Closure $href): self
  // factory для kind === 'link'; $href($row): ?string
```

Kind + form:

```php
->kind(string $kind): self  // 'link' | 'instant' | 'confirm' | 'form'
->instant(): self
->confirm(?string $text = null): self
->form(?string $formRequest = null, ?string $slot = null): self
->schema(array<int, FormField|FieldRow> $schema): self
->formRequest(string $class): self
->slot(string $name): self
```

Handler / inline-callback:

```php
->handler(string $actionClass): self
->using(\Closure $callback): self  // ($row, $payload, ?$actor): ActionResult
```

Authorization:

```php
->ability(?string $ability): self
->policy(string $policyClass, string $method): self
```

UI / hide-rules:

```php
->icon(string $icon): self
->variant(string $variant): self
->tooltip(string $text): self
->reloadAfterSubmit(bool $reload = true): self
->hideWhen(\Closure $callback): self  // ($row): bool — per-row visibility
```

Hook'и валидации (как у BulkAction):

```php
->prepareInput(\Closure $fn): self
->withValidator(\Closure $fn): self
->transformValidated(\Closure $fn): self
```

Preview / undo / flash:

```php
->preview(\Closure $callback): self  // ($row, $payload): View|string|array
->undoable(\Closure $capture, \Closure $reverse): self  // как у BulkAction; $capture получает [$row->getKey()]
->onSuccess(\Closure $cb): self
->onError(\Closure $cb): self
```

RowAction **не имеет** `queue()` / `queueChunkSize()` / `queueWhen()` / `payload(array)` / `confirmText()` — row-action всегда sync-исполнение, и payload приходит только из формы / request.

#### `Mercurio\Tables\Action\ActionResult`

Read-only VO с `public readonly` properties (доступ через `$result->affected`, **не** `$result->affected()`). Конструктор:

```php
new ActionResult(
    int $affected,
    int $missing = 0,
    ?string $message = null,
    int $denied = 0,
    int $skipped = 0,
    ?int $requested = null,
)
```

Свойства (`public readonly`):

- `affected: int` — успешно обработанные записи.
- `missing: int` — id'шники, которых нет в `query()` (фильтры отсекли, либо удалены между preview и submit'ом).
- `message: ?string` — короткое flash-сообщение по умолчанию (overridable через `BulkAction::onSuccess` / `RowAction::onSuccess`).
- `denied: int` — отказы политики/Gate per-id.
- `skipped: int` — записи, которые handler сознательно пропустил.
- `requested: ?int` — общее число запрошенных id'шников до фильтрации (используется engine'ом для `tables_action_log.subjects_count_total`).

Метод:

```php
counts(): array<string, int>
  // non-zero счётчики + 'requested' если задан; используется в логах и default-flash'ах
```

**Пример (рекомендуется именованный вызов из-за переставленного порядка после `$missing`):**

```php
return new ActionResult(
    affected: 5,
    missing: 2,
    message: 'Обработано 5 записей',
    denied: 0,
    skipped: 1,
    requested: 8,
);
```

#### `Mercurio\Tables\Jobs\BulkActionJob`

`ShouldQueue`-job для `->queue()` action'ов. FQCN кастомизируется через `config('tables.bulk_progress.job_class')`. Public — на случай override через подкласс.

### Saved views & filters

- `Mercurio\Tables\View\SavedView` — VO. Конструкторы: `::all(string $label = 'Все')`, `::scope(string $key, string $label, string $modelScopeName)`, `::query(string $key, string $label, \Closure $closure)`. Fluent: `->default(bool $value = true)`, `->position(int $value)`, `->color(?string $value)`, `->icon(?string $value)`, `->countWith(\Closure $cb)` (override count-query для saved-view counters).
- `Mercurio\Tables\Filter\Operator` — enum: `Eq`, `Neq`, `In`, `NotIn`, `Contains`, `NotContains`, `StartsWith`, `NotStartsWith`, `EndsWith`, `NotEndsWith`, `Between`, `NotBetween`, `Empty_` (case с trailing underscore, потому что `empty` зарезервировано в PHP; `value === 'empty'`), `NotEmpty`, `Gt`, `Lt`, `Gte`, `Lte`.
- `Mercurio\Tables\Filter\FilterCondition` — VO одного условия. Передаётся в `Field::filterUsing(\Closure)` callback. Public `readonly` properties: `field: string`, `operator: Operator`, `value: mixed`.

### Page chrome

- `Mercurio\Tables\Page\HeaderAction::make(string $label, string $url)` + fluent `icon(string)`, `variant(string)`, `size(string)`, `target(?string)`, `attrs(array<string, string>)`. Все fluent-методы immutable (возвращают новый VO).
- `Mercurio\Tables\Page\Breadcrumb::link(string $label, string $url)`, `Breadcrumb::current(string $label)`.
- `Mercurio\Tables\Page\EmptyState::make(string $title)` + `description(string)`, `icon(string $iconName)`, `cta(HeaderAction)`. Все fluent-методы immutable.

### Summary

- `Mercurio\Tables\Summary\Summary` (final) — контейнер: `new Summary(array<int, SummaryCard> $cards)`.
- `Mercurio\Tables\Summary\SummaryCard` (abstract) — точка расширения. Обязательный `abstract public function cellView(): string` возвращает имя Blade-компонента (например, `'tables::kpi-card'`).
- `Mercurio\Tables\Summary\KpiCard extends SummaryCard` (final) — `cellView()` = `'tables::kpi-card'`. Конструктор без изменений.
- `Mercurio\Tables\Summary\FunnelCard extends SummaryCard` (final) — `cellView()` = `'tables::funnel-card'`. Конструктор без изменений.
- `Mercurio\Tables\Summary\SummaryCardRegistry` (final) — singleton-реестр host-кастомных карточек по slug'у. Методы: `register(string $key, class-string<SummaryCard> $class): void`, `has(string $key): bool`, `resolve(string $key): class-string<SummaryCard>`, `all(): array<string, class-string<SummaryCard>>`. Pre-registered: `'kpi'`, `'funnel'`. Регистрация в host — через `config('tables.summary_cards')` (см. ниже).
- Рендер карточек в `<x-tables.summary>` обёрнут `try/catch` — на исключении `Log::error('tables.summary.card_render_failed', ['view' => ..., 'class' => ..., 'error' => ...])` + плейсхолдер `tables::summary.card_render_failed`. Stacktrace — только при `config('app.debug')`.
- Карточки разных типов в одной `Summary` допустимы (контейнер не привязан к одному типу).
- Подробности — [docs/summary-cards.md](summary-cards.md).

### Models (Eloquent — public по факту вынесения миграций тегом `tables-migrations`)

- `Mercurio\Tables\Models\SavedView`.
- `Mercurio\Tables\Models\UserTablePrefs`.
- `Mercurio\Tables\Models\ActionLog`.
- `Mercurio\Tables\Models\ActionProgress`.

Имена таблиц параметризуются через `config('tables.tables.*')`.

### Services (resolve через DI)

- `Mercurio\Tables\Services\SystemViewSyncer` — ручной триггер `php artisan tables:sync-saved-views`.
- `Mercurio\Tables\Services\SavedViewCountsCalculator`.
- `Mercurio\Tables\Services\UserSavedViewLoader`.
- `Mercurio\Tables\Services\ActionLogWriter`.
- `Mercurio\Tables\Prefs\UserPrefs` (VO), `Mercurio\Tables\Prefs\UserPrefsResolver`.

Override через `app()->bind(...)` — поддерживается.

### Export

- `Mercurio\Tables\Export\ExportRequest` — `final readonly` VO параметров экспорта (`filename`, `delimiter`, `enclosure`, `escape`, `bom`, `chunkSize`, `logChunks`, `columns: array<int, Field>`, `format = 'csv'`). Состояние списка (q/f/qb/sort/dir/columns) собирается отдельно через `ListResource::exportState($request)`.
- `Mercurio\Tables\Export\ExportWriter` — публичный интерфейс writer'а: `open(ExportRequest)`, `writeHeader(array<int, string>)`, `writeRow(array<int, string>)`, `close()`, `contentType(): string`, `fileExtension(): string`. `ExportHandler` гарантирует `close()` в `finally`.
- `Mercurio\Tables\Export\ExportWriterRegistry` — singleton, `register(string $format, class-string<ExportWriter>): void`, `has(string): bool`, `make(string): ExportWriter`, `formats(): array<int, string>`, `all(): array<string, class-string<ExportWriter>>`. CSV pre-registered.
- `Mercurio\Tables\Export\CsvStreamWriter implements ExportWriter` — default writer; стримит в `php://output`. Статический `CsvStreamWriter::stream(ExportRequest, Builder, ?Closure $logger)` сохранён как deprecated wrapper.
- `Mercurio\Tables\Export\JsonStreamWriter implements ExportWriter` — JSON-массив объектов (header → keys), UTF-8 без BOM.
- `Mercurio\Tables\Export\XlsxStreamWriter implements ExportWriter` — opt-in writer на `openspout/openspout` (host-managed dep). Без библиотеки `open()` бросает `RuntimeException`.
- `Mercurio\Tables\Export\ExportJobDispatcher` — interface для async fallback (пользователь реализует и биндит в DI). Метод `dispatch(string $resourceClass, array<string, mixed> $queryParams, int $userId, int $estimatedRows): string`.
- Подробности и custom writer recipe — [docs/export.md](export.md).

### Console

- `php artisan tables:sync-saved-views` (`Mercurio\Tables\Console\SyncSavedViewsCommand`).

### Support

- `Mercurio\Tables\Support\TableStateKeys` — public константа `STATE` (whitelist URL-state ключей saved view'а): `['q', 'f', 'qb', 'sort', 'dir', 'columns', 'density', 'per_page']`. JS-копия в `resources/js/tables/saved-views.js::STATE_WHITELIST` синхронизируется вручную (anchor-comment).

### Config keys (`config/tables.php`)

Полный список — см. CHANGELOG `### Public API matrix v0.1.0` → блок `**Config keys**`. Вкратце:

`guard`, `route_prefix`, `default_per_page`, `partial_header`, `js_event_prefix`, `resources`, `autocomplete_*`, `qb_*`, `sync_system_views`, `saved_view_color_palette`, `saved_view_icons`, `row_actions.*`, `bulk_actions.*`, `cell_edit.*`, `user_prefs.*`, `export.*`, `shell.*`, `summary_cards`, `action_log.*`, `bulk_progress.*`, `tables.*` (DB table names override).

Изменение default-значений — non-breaking (patch). Удаление ключа — breaking (major). Переименование — breaking + deprecation cycle.

### Blade public surface

- `tables::shell` — view-файл обёртки страницы (`@extends($layout)` + breadcrumbs + page-head + flashes + `<x-tables.page>`). Используется через `return view('tables::shell', ['table' => $table, 'bulkActionUrl' => ...])` в `HandlesResourceListing::index()`. **Не x-компонент**.
- `<x-tables.page :table="$table">` — основной layout страницы списка (saved-views + filter-bar + bulk-bar + table-root + offcanvas'ы). Имеет несколько именованных слотов для встраивания пользовательских элементов между секциями: `summary` (override блока KPI/funnel), `beforePageHead` / `afterPageHead`, `beforeSavedViews` / `afterSavedViews`, `beforeFilterBar` / `filterBar` / `filterBarRight` / `afterFilterBar`, `beforeTable` / `afterTable`, `afterPagination`. Формальная документация каждого слота — отдельная задача (F5); здесь — поверхностный список. **`EmptyState` рендерится автоматически из `$table->emptyState`, отдельного слота нет.**
- `<x-tables.table-root :table="$table"/>` — рендер собственно таблицы + пагинатор + summary внутри. Используется внутри `<x-tables.page>`, а также напрямую в view `tables::partial` (это view, который возвращает AJAX-route с заголовком `X-Tables-Partial`).

`vendor:publish --tag=tables-views` — escape hatch на крайний случай (override через копию в `resources/views/vendor/tables/`).

### Publishable tags

- `tables-config` → `config/tables.php`.
- `tables-migrations` → миграции `tables_saved_views`, `tables_user_table_prefs`, `tables_action_log`, `tables_action_progress`.
- `tables-views` → `resources/views/components/tables/*` + корневые view-файлы.
- `tables-assets` → `resources/{js,scss}` пакета.
- `tables-lang` → `resources/lang/{ru,en}/*` (12 групп: `action_log`, `bulk`, `cell`, `confirm`, `export`, `filters`, `prefs`, `qb`, `row_actions`, `saved_views`, `shell`, `summary`). Подробности — [docs/i18n.md](i18n.md).

### Localization (i18n)

- Translation namespace — `tables::*`. Зарегистрирован через `loadTranslationsFrom(__DIR__.'/../resources/lang', 'tables')` в `TablesServiceProvider::boot()`. Все Blade-шаблоны движка используют `__('tables::<group>.<key>', $params)`.
- Runtime JS получает translations через `window.TablesI18n` (PHP serializer — `Mercurio\Tables\Support\JsTranslations::payload()`, whitelist в `JsTranslations::whitelist()`) + helper `tablesT(key, params)` из `resources/js/tables/i18n.js`. Missing key → `console.error('tables.i18n missing key', key)` + возврат самого ключа.
- Дефолты в `config/tables.php` для UI-меток (`qb_button_label`, `export.button_label`, `user_prefs.popover_button_label`, `action_log.header_action_label`) — translation keys (`tables::<group>.<key>`); read-сайты в Blade оборачивают значение в `__($value)`, поэтому host может передавать как translation key, так и готовую строку.
- `SavedView::all(label)` / `SavedView::scope(key, label, ...)` — `$label` может быть translation key или обычным текстом; Blade рендерит через `__($view->label)`.

### JS events

Префикс конфигурируется через `config('tables.js_event_prefix')`, default `tables`.

- `tables:rendered` — таблица перерендерена (после navigate / поиска / фильтра). Эмиттится через `document.dispatchEvent(new CustomEvent(...))`.
- `tables:navigate` — выполнен `pushState` с новым URL. CustomEvent.
- `tables:total-changed` — изменился total-counter. CustomEvent.
- `tables:flash` — flash-сообщение (`status`/`warning`/`error`) от engine. Эмиттится через `$(document).trigger('tables:flash', [{ status: msg }])` (jQuery — для совместимости с Bootstrap toast'ами host'а).

Payload-объекты не специфицируются формально (документируются в коде ивент-эмиттеров); поломка структуры payload — minor breaking (рассматривается как deprecation на следующий минор).

---

## Advanced: Source contract (с v2)

`Mercurio\Tables\Source\Source` — единая точка входа для источника данных
Resource'а. В v2 единственная встроенная реализация — `EloquentSource`
(адаптер поверх `Eloquent\Builder`); цель абстракции — подключение
не-Eloquent источников в следующих фазах (`ArraySource`, `SqlSource`,
`HttpSource`, `FileSource`).

```php
namespace Mercurio\Tables\Source;

interface Source
{
    public function capabilities(): Capabilities;
    public function withQuery(Query $q): static;
    public function count(): ?int;                         // null = cursor-режим без count
    public function page(int $page, int $perPage): Page;
    public function stream(int $chunkSize): \Generator;
    public function find(int|string $id): mixed;
    public function findMany(array $ids): iterable;
    public function update(int|string $id, array $changes): mixed;
    public function probe(): mixed;
}
```

`Capabilities` декларирует, какие операции источник умеет (`filter`, `sort`,
`search`, `count`, `cursor`, `mutate`, `stream`). UI и engine используют это
для корректной деградации — например, `mutate=false` источник прячет
bulk/row-write/inline-edit/undo (gating в Blade появится в Phase 2).

`Query` — neutral VO состояния запроса (`search`, `searchableColumns`,
`conditions: FilterCondition[]`, `qbRoot: AtomCondition|AtomGroup|null`,
`sortField`, `sortDirection`, `savedViewKey`). Source-драйвер транслирует
это в свой подъязык (SQL/HTTP params/in-memory predicate).

`Page` — унифицированный результат пагинации; два режима:
- **offset** (`total !== null`) — обычные номера страниц; внутри Page хранит
  `LengthAwarePaginator`-делегата для рендера Blade-шаблона.
- **cursor** (`total === null`) — opt-in для HTTP/файловых источников;
  `nextCursor` / `prevCursor` вместо номеров. Blade-пагинатор
  (`tables::pagination-bs5`) ветвится через `@if ($paginator->isCursor())`.

Резолв источника всегда через `ListResource::resolveSource(): Source`
(final-метод): сначала `source()`, потом legacy `query()` → `EloquentSource`.

Внутренний pipeline пакета не делает `instanceof Builder` — всё ходит
через `Source` / `Query` / `Page`. Для legacy host-консьюмеров, читавших
`$state['builder']` из `exportState()`, временный путь — `$state['source']
->getBuilder()` для `EloquentSource` (метод помечен `@internal`).

---

## Internal symbols (subject to change without semver bump)

> Перечисленные ниже классы помечены `@internal` PHPDoc. Прямое использование (extends/instanceof/import в пользовательском коде) возможно, но не поддерживается: minor- и patch-релизы могут изменить сигнатуры, переименовать классы, объединить или разделить их.

### Action helpers/handlers

- `Mercurio\Tables\Action\Helpers\ActionAuthorizer`
- `Mercurio\Tables\Action\Helpers\ActionPayloadResolver`
- `Mercurio\Tables\Action\Helpers\ActionResponseBuilder`
- `Mercurio\Tables\Action\Handlers\BulkActionHandler`
- `Mercurio\Tables\Action\Handlers\RowActionHandler`
- `Mercurio\Tables\Action\Handlers\ActionLogHandler`

### Services (handlers, не resolve-targets)

- `Mercurio\Tables\Services\UserViewHandler`
- `Mercurio\Tables\Services\CellUpdateHandler`

### Export

- `Mercurio\Tables\Export\ExportHandler`

### Filters

- `Mercurio\Tables\Filter\FilterParser`
- `Mercurio\Tables\Filter\FilterApplier`
- `Mercurio\Tables\Filter\Qb\AtomCondition`
- `Mercurio\Tables\Filter\Qb\AtomGroup`
- `Mercurio\Tables\Filter\Qb\QueryBuilderApplier`
- `Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer`
- `Mercurio\Tables\Filter\Qb\QueryBuilderParser`

### Routing & HTTP

- `Mercurio\Tables\Routing\PendingTablesResource`
- `Mercurio\Tables\Http\GenericTablesController`

### Support

- `Mercurio\Tables\Support\Pluralizer`
- `Mercurio\Tables\Support\UserViewHref`

(`Mercurio\Tables\Support\TableStateKeys` — **Stable**, см. выше.)

---

## Known warts (fixable in v0.2 with deprecation cycle)

*(заполняется по факту — пустой список к моменту freeze'а допустим. Если в процессе будет обнаружено спорное имя/тип публичного API, оно добавляется сюда без правок самого кода.)*

---

## Versioning policy

`mercurioplatform/tables` следует [SemVer 2.0](https://semver.org/spec/v2.0.0.html) от тэга `v0.1.0` (ставится в `Tables-engine-extract-to-org`).

| Изменение | Bump |
|---|---|
| Новый Field-подкласс / новый method override-point на `ListResource` / новый config key с default'ом | minor |
| Изменение default'а config key | patch |
| Bug fix без поломки сигнатур | patch |
| Изменение сигнатуры public-метода / удаление public-symbol / переименование config-key | major (с deprecation cycle на minor) |
| Изменение internal-symbol | любая версия (даже patch) |
| Изменение JS event payload structure | minor (с anchor-warn в CHANGELOG) |
| Удаление JS event | major |

Pre-1.0 (`v0.x`): minor-bump допускает breaking changes на public API при условии явной записи в CHANGELOG `### Breaking changes`. С `v1.0` — только в major.
