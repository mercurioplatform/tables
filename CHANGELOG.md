# Changelog

All notable changes to `mercurioplatform/tables` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Field::editableUsing(policy?, rules?, transform?, column?, options?)` — единая
  декларативная точка конфигурации inline cell-edit. Все 5 старых методов
  (`editable`, `editColumn`, `editPolicy`, `editRules`, `editOptions`) сохранены
  как тонкие wrappers и работают как раньше.
- Параметр `transform` — `Closure(mixed $value, Model $model): mixed`,
  вызывается в `CellUpdateHandler` после валидации, до `update()`. Любой
  Throwable из transform логируется (`tables.cell_edit.transform_failed`)
  и возвращает `422` с translation-ключом `tables::cell_edit.transform_failed`
  (поставляется в ru/en).
- `ListResource::cellEditEnabled(): bool` (default `true`). Если переопределить
  в `false`, route `PATCH {base}/cells/{id}/{field}` не регистрируется через
  `Route::tablesPage(...)` (boot-time probe). Любая ошибка резолва Resource'а
  при probe трактуется как fallback `true` с записью
  `Log::warning('tables.routing.cell_edit_probe_failed', ...)`.
- `Field::getCellEditSpec(): ?CellEditSpec` — публичный геттер для внутренних
  сервисов и host-расширений; внутреннее представление настроек cell-edit.

- `RowAction::sharedAuthz()` — opt-in флаг для row-actions, чья policy/ability
  не зависит от состояния конкретной модели. При установленном флаге
  `resolveRowActions()` выполняет один `Gate::check` на класс модели и
  переиспользует результат для всех строк того же класса. На странице
  из 25 строк × 4 row-actions это снижает количество Gate-вызовов с 100
  до 4 (для shared-authz actions). Без флага — текущее поведение (per-row).

### Performance

- **`ListResource` memoization.** Hot-path методы `fields()`, `savedViews()`,
  `bulkActions()`, `rowActions()` теперь резолвятся один раз за request и кешируются
  в private property на instance Resource'а. До патча `fields()` мог вызываться
  9 раз за один `index()` запрос (Filter/Qb/Prefs/UserView/ListResource зовут
  одну и ту же функцию). Если ваш Resource внутри `fields()` ходит в БД
  (autocomplete-опции, динамические options) — это убирает 8 повторных запросов
  на странице.
- Override-точки (`fields()` / `savedViews()` / `bulkActions()` / `rowActions()`)
  работают без изменений — мемо-слой прозрачен.

### Breaking changes

- `Mercurio\Tables\Summary\KpiSummary` и `FunnelSummary` удалены. Используйте `new Summary([...])` напрямую — `Summary` теперь `final` контейнер (раньше abstract).
- Добавлен `Mercurio\Tables\Summary\SummaryCard` (abstract) с обязательным `cellView(): string`. `KpiCard` / `FunnelCard` теперь его наследуют. Публичные конструкторы карточек не изменились — host-named-arguments стабильны.
- Blade-шаблоны `tables::kpi-summary` / `tables::funnel-summary` удалены — рендером занимается единый `tables::summary` через `<x-dynamic-component>`.
- CSS-классы `.tables-summary--kpi` / `.tables-summary--funnel` удалены. Контейнер использует `grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))` (раньше: фиксированные 4 колонки для KPI, auto-fit 140px для funnel). Host может переопределить через CSS-переменные.
- Миграция call-site'ов: `new KpiSummary($cards)` / `new FunnelSummary($cards)` → `new Summary($cards)`.
- `@internal` `Mercurio\Tables\Services\UserSavedViewLoader::loadFor(string $resourceKey)` → `loadFor(\Mercurio\Tables\ListResource $resource)`. Класс отмечен `@internal`, но если хост ранее обращался к нему напрямую — заменить аргумент на сам Resource.
- `@internal` `Mercurio\Tables\Action\Helpers\ActionAuthorizer::authorizeAction(...)` — последний аргумент `string $resourceClass` заменён на `\Mercurio\Tables\ListResource $resource`. `currentTableActor()` / `resolveAuditActorId()` получили опциональный параметр `?ListResource $resource = null`.

### Documentation

- `docs/api.md` приведён в соответствие реальному коду. Исправлено:
  - `HandlesResourceListing`: `prefs(...)` заменён на `savePrefs(...)` + `resetPrefs(...)`,
    `cellEdit(...)` → `cellUpdate(...)`, несуществующий список
    `savedViewSave/Update/Delete/SetDefault` заменён на реальные `saveView(Request)` +
    `deleteUserView(Request, int $id)`. Добавлены `bulkActionPreview(Request, string $action)`
    и `rowActionPreview(Request, $id, string $action)`. Поправлен порядок аргументов
    `rowAction`/`rowActionForm` на `(Request, $id, string $action)`. Типизирован
    `actionLogUndo(Request, int $logId)`. Имена параметров приведены к коду
    (`$name → $action`, `$progressId → $progress`).
  - `Field` DSL: удалены несуществующие fluent-методы `label(string)`, `searchable(bool|string)`,
    `width(string)`, `editStep(float)`, `editMin(int|float)`, `editMax(int|float)`.
    Поправлены сигнатуры: `make(string $name, ?string $label = null)`,
    `sortable(bool $value = true)` (без `|string`), `editable(bool $value = true)`
    (раньше заявлен `?\Closure $rules`; rules задаются отдельно через `editRules(array|Closure)`),
    `filterable(array $operators = [])`, `filterOptions(\Closure|array $optionsOrFn)`,
    `onlyFilterable(bool $value = true)`. Comment-hint'ы callback'ов `subline`/`linkTo`
    выровнены с реальным рендером — оба получают `($value, $row)`, не `($row)`.
    Добавлены публичные `filterGroup(string)`, `filterPopover(string)`,
    `filterAutocomplete(bool)`.
  - `Action`: `Mercurio\Tables\Action\Action` — это `interface` с единственным методом
    `execute(mixed $subject, array $payload): ActionResult`, не abstract base.
    `BulkAction`/`RowAction` его не имплементируют; интерфейс реализуют handler-классы,
    передаваемые в `->handler(string $actionClass)`.
  - `BulkAction`: `confirm(?string $text = null)` (Closure не поддерживается),
    `undoable(\Closure $capture, \Closure $reverse)` — два Closure, не один;
    `queueWhen(int $threshold)` — int, не Closure. Добавлен mutex `queue()` ↔ `using()`.
    Перечислены недостающие fluent-методы (`kind`, `confirmText`, `formRequest`, `slot`,
    `reloadAfterSubmit`, `tooltip`, `ability`).
  - `RowAction`: `link(string $name, string $label, \Closure $href)` — factory-метод,
    не fluent setter. Расписан полный набор fluent-методов (раньше шёл общим намёком
    на «то же, что BulkAction»).
  - `ActionResult`: убран несуществовавший параметр `array $flash = []`. Добавлен реальный
    `?int $requested = null` (6-й параметр). Переставлен порядок: `(affected, missing,
    message, denied, skipped, requested)`. Удалены несуществующие getter-методы
    `affected()/missing()/skipped()/denied()/message()/flash()` — всё доступно как
    `public readonly` properties (`$result->affected`). Единственный метод — `counts()`.
  - `ListResource`: убран override-point `policy(): ?string` (его нет в коде; policy
    декларируется на уровне action'а). Добавлены реально существующие override'ы
    `density()`, `layout()`, `filterGroupLabels()`, `filterGroupThreshold()`.
  - `ResourceTable`: уточнён список public-properties (убраны `total`/`prefs` —
    их нет; добавлены реально публичные `key`, `search`, `activeFilters`, `qb`,
    `savedViewCounts`, `effectiveColumns`, `perPage`, `emptyState`). `rows` —
    метод, не property. Список public-методов расписан.
  - `ResourceRegistry`: убран несуществующий `find(string $key): ?ListResource`,
    добавлены `all()` и `classes()`.
  - `Route` macros: имена параметров приведены к реальным (`$uri → $path`,
    `$controllerClass → $controller`).
  - `Form\Field`: `SelectField::enum` — без 4-го параметра `$labeler`;
    `SelectField::relation(string $name, string $label, \Closure $resolver)` —
    реальная сигнатура (раньше заявлена `(string $modelClass, ?string $labelColumn)`).
    Fluent-методы `FormField` пересмотрены: убраны несуществующие `placeholder`,
    `disabled`, `default`, `help`; добавлены реальные `value`, `valueFrom`, `helper`,
    `attrs`, `attribute`.
  - `SavedView`: фактические сигнатуры `color(?string)`, `icon(?string)` + добавлен
    `countWith(\Closure)`. `Operator::Empty` уточнён до `Operator::Empty_` (case с
    trailing underscore; `value === 'empty'`).
  - `FilterCondition`: удалено несуществующее свойство `not` — у VO только
    `field/operator/value`.
  - `Page\HeaderAction`/`Breadcrumb`: имена параметров приведены к коду
    (`$href → $url`), добавлены `HeaderAction::size`/`target`.
  - `Export\ExportRequest`: описание уточнено — это VO параметров CSV-export'а
    (filename/delimiter/encoding/columns), не «состояния списка».
  - `Blade public surface`: `tables::shell` — это view-файл, не x-компонент.
    `<x-tables.page>` принимает prop `:table`, не `:resource`; слоты в page
    компоненте: `summary` + набор `before*`/`after*` (не `header`/`empty-state`).
  - `JS events`: удалён `tables:filter-groups` — это localStorage-prefix, не event.
    Эмит-механизм для `tables:flash` уточнён (jQuery trigger).

  Это **не breaking change**: документация была неточной с момента freeze'а v0.1.0,
  пользователи, опиравшиеся на отсутствующие методы (`cellEdit`, `prefs`, `savedViewSave`,
  `Field::label`, `ActionResult::affected()` и т.п.), получали `BadMethodCallException`
  или `TypeError` при первом вызове и не могли построить на этом рабочий код. Публичный
  surface самого кода (`tables/src/`) не менялся; источник правды — код.

### Changed

- **`ListResource` декомпозиция.** Логика сборки `ResourceTable` извлечена в
  `Mercurio\Tables\Table\TableBuilder` (`@internal`, регистрируется как
  singleton). Применение search + saved-view + chip-фильтров + AST query
  builder'а переехало в `Mercurio\Tables\Filter\FilterPipeline`. Резолв
  сортировки — в `Mercurio\Tables\Table\SortResolver` (static helper).
  `ListResource::table()` и `exportState()` теперь — тонкие фасады поверх
  `app(TableBuilder::class)`. Публичный API не меняется: host-Resource'ы
  продолжают наследоваться от `ListResource`, сигнатуры `fields()` /
  `savedViews()` / `bulkActions()` / `rowActions()` / `findField()` /
  `filterOptions()` / `qbSchema()` / `astToArray()` остаются. Размер
  `ListResource.php` сократился с 833 до 551 LOC.
- Summary block теперь рендерится между subtitle и Saved Views (ранее — после Saved Views).
  Затрагивает только дефолтный shell; кастомные `page_head_component`-переопределения не
  затронуты. Добавлен безусловный wrapper `<div data-tables-summary>` (стабильная точка
  монтирования для будущего AJAX-обновления partial). Smoke-tested via Blade source
  review only — host-приложение в этой итерации локально не поднималось.
- AJAX-partial теперь рендерит и обновляет блок summary через стабильный wrapper
  `<div data-tables-summary>`. Раньше KPI-карточки оставались со значениями полного
  reload'а и не пересчитывались при фильтрации / сортировке / пагинации / переключении
  saved-view. Затрагивает host-проекты, использующие дефолтный `tables::partial`;
  кастомные переопределения partial-вьюхи не затронуты (swap пропускается no-op'ом,
  summary остаётся прежним). Smoke-tested via Blade + JS source review and syntax check
  only — host-приложение локально не поднималось.
- Engine-компонент `tables.page-head` (runtime fallback для `shell.page_head_component`,
  активируется у host'ов без published `config/tables.php` либо с `null` для этого
  ключа) больше не рендерит внутренний placeholder `<p data-tables-subtitle>`. Раньше
  при этом fallback'е в DOM присутствовало два элемента с атрибутом
  `data-tables-subtitle` (внешний из `shell.blade.php` + внутренний из page-head),
  и AJAX-swap subtitle обновлял только первый в tree-order. Теперь единственный
  placeholder для full-страницы живёт в `shell.blade.php` (снаружи page-head),
  для AJAX-фрагмента — в `partial.blade.php`. Из `@props` page-head'а удалены
  ключи `subtitle` и `subtitleAttr` — эти prop'ы не задокументированы в
  `docs/api.md` и не были частью публичного контракта. Host-проекты с published
  `config/tables.php` (где `page_head_component` указывает на собственный
  компонент без `data-tables-subtitle`) поведения не меняют. Smoke-tested via
  Blade source review only — host-приложение в этой итерации локально не
  поднималось.
- `ListResource::resolveAuditActor(?int $actorId): ?string` теперь имеет дефолтную
  реализацию вместо `return null`. Резолв идёт через Laravel auth-провайдер,
  привязанный к `config('tables.guard')`:
  `Auth::createUserProvider(config('auth.guards.{guard}.provider'))->retrieveById($actorId)`.
  Найденный `Authenticatable` форматируется новым protected hook'ом
  `formatAuditActor(?Authenticatable $user): ?string` (default —
  `data_get($user, 'name') ?? data_get($user, 'email') ?? null`). Раньше метод всегда
  возвращал `null`, и host-проекты в `mercurioplatform/` повторяли один и тот же
  `Model::find()` + `name ?? email` в каждом Resource'е (5 копий). Конфигурационные
  аномалии host'а (provider не найден в `auth.providers`, driver не зарегистрирован —
  `InvalidArgumentException` от `AuthManager::createUserProvider`, имя провайдера
  пустое) graceful'но логируются через `Log::warning` (три раздельные ветки:
  `tables.audit_actor.guard_provider_missing`, `tables.audit_actor.provider_driver_invalid`,
  `tables.audit_actor.provider_not_resolvable`); offcanvas всегда открывается, UI
  fallback'ится на `#$id`. Операционные ошибки (`QueryException` от `retrieveById`)
  пробрасываются — Blade-consumer (`resources/views/action-log.blade.php`) уже
  толерантен через `try/catch`. Включён per-instance memoization
  (`private array $resolvedAuditActors`) — экономит lookup'ы в пределах одной
  страницы offcanvas. Override-точки сохранены: для нестандартного lookup'а
  (`withTrashed`, мульти-источники) — переопределить `resolveAuditActor`; для
  смены формата (`first_name + last_name`, `display_name`) — переопределить
  `formatAuditActor`. Public-сигнатура `resolveAuditActor(?int $actorId): ?string`
  не меняется; добавление protected-hook'а `formatAuditActor` — non-breaking (см.
  `docs/api.md` invariant о добавлении новых методов). Записи
  `tables_action_log.actor_id` (`unsignedBigInteger nullable`) и behaviour
  `ActionAuthorizer::resolveAuditActorId()` не затрагиваются. Smoke-tested via
  [DevTools на host'е mercurioplatform + проверка negative-case через временно
  изменённый `auth.providers.driver` / tinker-fallback при недоступности host'а —
  статус актуализировать при выполнении Task 7].

### Added

- `Mercurio\Tables\ListResource::guard(): ?string` — override-точка для auth guard'а. Дефолт `null` = `config('tables.guard', 'web')`. Используется engine'ом во всех точках актора (policy/Gate, prefs, saved views, экспорт, audit). Позволяет держать на одной странице Resource'ы с разными guard'ами без подмены глобального конфига.
- `Mercurio\Tables\ListResource::effectiveGuard(): string` (final) — резолвит override или конфиг. Использовать в host-коде и кастомных интеграциях вместо чтения `config('tables.guard')`.
- Protected hook `Mercurio\Tables\ListResource::formatAuditActor(?Authenticatable $user): ?string`
  — override-точка для смены формата отображаемого имени актора в offcanvas
  «История» без копирования lookup-логики. Default возвращает
  `data_get($user, 'name') ?? data_get($user, 'email') ?? null`. Через `data_get` —
  потому что interface `Authenticatable` не определяет свойства `name`/`email`
  (PHPStan level 6 не пропустил бы прямой property-access).

### Fixed

- `docs/api.md`: запись `resolveAuditActor` синхронизирована с реальной сигнатурой
  кода (`(?int $actorId): ?string` вместо устаревшего
  `(\Illuminate\Http\Request $request): ?\Illuminate\Contracts\Auth\Authenticatable`).
  Остальные расхождения api.md ↔ код запланированы под отдельный milestone — полный
  синк закроется одной правкой.

## [0.1.0] — 2026-05-09

### Notes

- Initial public release. Extracted from `mercurioplatform` monorepo via `git subtree split` (полная история коммитов сохранена).
- Публичный API заморожен под v0.1.0 (см. [`docs/api.md`](docs/api.md)).

### Public API matrix v0.1.0

> Полная матрица с per-symbol stability statements и versioning policy: [`docs/api.md`](docs/api.md).

**Resource & lifecycle**

- `Mercurio\Tables\ListResource` — abstract base; override-points: `key()`, `query()`, `fields()`, `searchable()`, `savedViews()`, `bulkActions()`, `rowActions()`, `summary()`, `perPage()`, `defaultSort()`, `routeBaseName()`, `policy()`, `pageTitle()`, `browserTitle()`, `subtitle($total)`, `breadcrumbs()`, `headerActions()`, `emptyState()`, `flashKeys()`, `actionHistoryEnabled()`, `resolveAuditActor()`.
- `Mercurio\Tables\ResourceTable` — runtime descriptor собранной таблицы (передаётся в `<x-tables.shell>` / `<x-tables.page>`).
- `Mercurio\Tables\ResourceRegistry` — lookup ResourceClass по `key()`; используется sync-savedviews и Query Builder.
- `Mercurio\Tables\Concerns\HandlesResourceListing` — controller trait: `index()`, `bulkAction()`, `bulkActionForm()`, `rowAction()`, `rowActionForm()`, `actionLog()`, `actionLogUndo()`, `prefs()`, `savedView*()`, `export()`, `cellEdit()`, `actionProgress()`, `options()`.
- `Route::tablesResource(uri, controller)` — macro; регистрирует все JSON-эндпоинты на собственный контроллер с `HandlesResourceListing`.
- `Route::tablesPage(uri, ResourceClass)` — macro; декларативный путь, контроллер не нужен (используется встроенный `Mercurio\Tables\Http\GenericTablesController`).
- `Mercurio\Tables\Routing\PendingTablesResource` — fluent билдер, возвращаемый макросами (`->name(...)`, `->middleware(...)`, ...).

**Fields**

- `Mercurio\Tables\Field\Field` — abstract; fluent-методы: `make`, `label`, `sortable`, `searchable`, `align`, `width`, `mono`, `cellView`, `editable`, `editColumn`, `editOptions`, `editRules`, `editPolicy`, `editStep`, `editMin`, `editMax`, `filterable`, `filterOptions`, `onlyFilterable`.
- Конкретные: `TextField`, `TwoLineField`, `NumberField`, `MoneyField`, `DiscountedMoneyField`, `BooleanField`, `StatusField`, `BadgesField`, `ImageField`, `AvatarField`, `BelongsToField`, `BelongsToManyField`, `RelationCountField`, `RatingField`, `ProgressBarField`, `ConditionalColorField`, `JsonField`, `TagsField`, `DateField`.

**Form fields** (используются в `BulkAction::schema(...)` / `RowAction::schema(...)`)

- `Mercurio\Tables\Form\Field\FormField` — abstract.
- `Mercurio\Tables\Form\Field\FieldRow` — горизонтальная группа полей.
- Конкретные: `TextField`, `TextareaField`, `NumberField`, `SelectField` (с `::enum`, `::relation`, `::options`), `RadioGroupField`, `CheckboxField`, `PlaintextField`.

**Actions**

- `Mercurio\Tables\Action\Action` — общий abstract для bulk и row.
- `Mercurio\Tables\Action\BulkAction` — fluent: `make`, `instant`, `confirm`, `preview`, `schema`, `withValidator`, `prepareInput`, `transformValidated`, `handler`, `using`, `payload`, `policy`, `onSuccess`, `onError`, `icon`, `variant`, `undoable`, `queue`, `queueChunkSize`, `queueWhen`.
- `Mercurio\Tables\Action\RowAction` — fluent: `make`, `link`, `confirm`, `preview`, `schema`, `withValidator`, `prepareInput`, `transformValidated`, `using`, `policy`, `onSuccess`, `onError`, `icon`, `tooltip`, `variant`, `hideWhen`, `undoable`.
- `Mercurio\Tables\Action\ActionResult` — return-VO для handlers: `affected`, `missing`, `skipped`, `denied`, `message`, `flash`, `counts()`.
- `Mercurio\Tables\Jobs\BulkActionJob` — встроенный `ShouldQueue`-job для `->queue()` (FQCN кастомизируется через `tables.bulk_progress.job_class`).

**Saved views & filters**

- `Mercurio\Tables\View\SavedView` — value-object; статические конструкторы `all`, `scope`; fluent `default`, `position`, `color`, `icon`.
- `Mercurio\Tables\Filter\Operator` — enum поддерживаемых операторов (Eq, In, NotIn, Between, NotBetween, Gt, Gte, Lt, Lte, Like, NotLike, IsNull, IsNotNull, ...).
- `Mercurio\Tables\Filter\FilterCondition` — value-object одного условия.

**Page chrome**

- `Mercurio\Tables\Page\HeaderAction` — fluent: `make($label, $href)`, `icon`, `variant`, `attrs`.
- `Mercurio\Tables\Page\Breadcrumb` — `link($label, $href)`, `current($label)`.
- `Mercurio\Tables\Page\EmptyState` — fluent: `make($title)`, `description`, `icon`, `cta`.

**Summary**

- `Mercurio\Tables\Summary\Summary` — abstract.
- `Mercurio\Tables\Summary\KpiSummary`, `KpiCard`.
- `Mercurio\Tables\Summary\FunnelSummary`, `FunnelCard`.

**Models** (Eloquent — public по факту вынесения миграций тегом `tables-migrations`)

- `Mercurio\Tables\Models\SavedView`.
- `Mercurio\Tables\Models\UserTablePrefs`.
- `Mercurio\Tables\Models\ActionLog`.
- `Mercurio\Tables\Models\ActionProgress`.

**Services**

- `Mercurio\Tables\Services\SystemViewSyncer` — sync system-views в БД из объявлений Resource'ов (вызывается в `boot()`, ручной триггер — `tables:sync-saved-views`).
- `Mercurio\Tables\Services\SavedViewCountsCalculator`.
- `Mercurio\Tables\Services\UserSavedViewLoader`.
- `Mercurio\Tables\Services\ActionLogWriter`.
- `Mercurio\Tables\Prefs\UserPrefs`, `Mercurio\Tables\Prefs\UserPrefsResolver`.

**Export**

- `Mercurio\Tables\Export\ExportRequest` — VO текущего состояния списка.
- `Mercurio\Tables\Export\CsvStreamWriter`.
- `Mercurio\Tables\Export\ExportJobDispatcher` — interface для async fallback.

**Console**

- `php artisan tables:sync-saved-views` (`Mercurio\Tables\Console\SyncSavedViewsCommand`) — sync system-views из всех зарегистрированных Resource'ов.

**Config keys** (`config/tables.php`)

- `guard`, `route_prefix`, `default_per_page`, `partial_header`, `js_event_prefix`, `resources`.
- `autocomplete_limit`, `autocomplete_min_chars`, `autocomplete_debounce_ms`, `route_options_suffix`.
- `qb_max_payload_size`, `qb_max_depth`, `qb_max_atoms`, `qb_button_label`, `qb_offcanvas_width`.
- `sync_system_views`, `saved_view_color_palette`, `saved_view_icons`.
- `row_actions.{suffix, form_suffix}`, `bulk_actions.form_suffix`, `cell_edit.{suffix, embed_options_limit}`.
- `user_prefs.{per_page_options, density_options, popover_button_label, popover_button_icon}`.
- `export.{sync_limit, chunk_size, csv_delimiter, csv_enclosure, csv_escape, csv_bom, filename_prefix, ability, async_dispatcher, log_chunks, button_label, button_icon}`.
- `shell.{layout, page_head_component, flash_keys, title_suffix}`.
- `action_log.{enabled, subjects_id_limit, recent_limit, per_page, header_action_label, header_action_icon, payload_max_bytes, undo_window_minutes, undo_snapshot_max_bytes}`.
- `bulk_progress.{enabled, default_chunk_size, default_threshold, poll_interval_ms, poll_max_duration_ms, progress_ttl_minutes, max_affected_ids_for_cta, job_class, job_tries, job_timeout_seconds, tray_position}`.
- `tables.{saved_views, user_table_prefs, action_log, action_progress}` — overrides DB-таблиц движка.

**Blade public surface**

- `<x-tables.shell :table="$table"/>` — обёртка страницы (layout + breadcrumbs + page-head + flashes).
- `<x-tables.page :resource="$table">` со слотами `header`, `summary`, `empty-state`.
- `<x-tables.table-root :table="$table"/>` — fragment, который рендерится при AJAX-навигации (`X-Tables-Partial`).

**Publishable tags**

- `tables-config` — `config/tables.php`.
- `tables-migrations` — миграции `saved_views`, `user_table_prefs`, `action_log`, `action_progress`.
- `tables-views` — `resources/views/components/tables/*` + корневые view-файлы (escape-hatch для override вёрстки).
- `tables-assets` — `resources/{js,scss}` пакета.

**JS events** (prefix configurable через `tables.js_event_prefix`, default `tables`)

- `tables:rendered` — таблица перерендерена (после navigate / поиска / фильтра).
- `tables:navigate` — выполнен `pushState` с новым URL.
- `tables:total-changed` — изменился total-counter.
- `tables:flash` — flash-сообщение (status / warning / error) от engine.
- `tables:filter-groups` — обновлены группы фильтров (advanced QB).

### Internal (`@internal`)

> Помечены `@internal` PHPDoc-маркерами в коде (`packages/tables/src/`). Полное описание стабильности — [`docs/api.md`](docs/api.md). Прямое использование возможно, но не поддерживается: minor/patch-релизы могут менять сигнатуры без deprecation cycle.

- `Mercurio\Tables\Action\Helpers\{ActionAuthorizer, ActionPayloadResolver, ActionResponseBuilder}`.
- `Mercurio\Tables\Action\Handlers\{BulkActionHandler, RowActionHandler, ActionLogHandler}`.
- `Mercurio\Tables\Services\{UserViewHandler, CellUpdateHandler}`.
- `Mercurio\Tables\Export\ExportHandler`.
- `Mercurio\Tables\Filter\{FilterParser, FilterApplier}`.
- `Mercurio\Tables\Filter\Qb\{AtomCondition, AtomGroup, QueryBuilderApplier, QueryBuilderNormalizer, QueryBuilderParser}`.
- `Mercurio\Tables\Routing\PendingTablesResource`.
- `Mercurio\Tables\Http\GenericTablesController`.
- `Mercurio\Tables\Support\{Pluralizer, UserViewHref}`.

[Unreleased]: https://github.com/mercurioplatform/tables/commits/main
