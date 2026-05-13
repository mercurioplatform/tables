# Changelog

All notable changes to `mercurioplatform/tables` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Summary block теперь рендерится между subtitle и Saved Views (ранее — после Saved Views).
  Затрагивает только дефолтный shell; кастомные `page_head_component`-переопределения не
  затронуты. Добавлен безусловный wrapper `<div data-tables-summary>` (стабильная точка
  монтирования для будущего AJAX-обновления partial). Smoke-tested via Blade source
  review only — host-приложение в этой итерации локально не поднималось.

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
