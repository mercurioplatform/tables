<?php

namespace Mercurio\Tables\Api;

use Illuminate\Http\Request;
use LogicException;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;
use Mercurio\Tables\Filter\Qb\Exceptions\QueryBuilderValidationException;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use Mercurio\Tables\Http\Controllers\JsonApiController;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Query;

/**
 * Парсер Request → {@see ParsedApiQuery}.
 *
 * Поддерживаемые query-параметры (GET) и top-level body keys (POST JSON):
 * - `?include=summary,savedViews,capabilities` — CSV блоков envelope'а;
 * - `?fields=id,number,total` — sparse-fieldsets (whitelist через `allowFields`);
 * - `?format=raw|formatted|both` — режим сериализации значений;
 * - `?per_page=N` — c min=1, max из `ApiConfig::maxPerPage`;
 * - `?page=N` — 1-based номер страницы;
 * - `?sort=field` или `?sort=-field` — desc через `-` префикс, whitelist через `allowFields`;
 * - `?q=…` — fulltext search (передаётся в `Query::$search`);
 * - `?savedView=key` — whitelist через `allowSavedViews`; conditions сливаются с `filter[..]`;
 * - `?filter[..]` — плоский AND-sugar (см. {@see self::parseFilters()});
 * - `?qb=<base64-json>` (GET) или `body.qb` (POST) — полноценное QB-дерево
 *   (OR-группы, NOT, вложенность) парсится через {@see QueryBuilderParser}
 *   в strict-режиме и складывается в {@see Query::$qbRoot}; флэт `?filter[..]`
 *   + savedView-conditions мерджатся в общий AND-root.
 *
 * Невалидный input → {@see ApiValidationException}; единая точка логирования —
 * {@see ApiErrorResponse::make()} (вызывается выше по стеку контроллером). Сам
 * парсер не пишет дублирующих `Log::warning`-строк перед throw'ами.
 *
 * Capabilities-gating (filter/sort/search/qbTree vs `Source::capabilities()`)
 * делает не парсер, а контроллер — см. {@see JsonApiController::assertCapabilities()}.
 *
 * Sentinel'ы `allowFields === null` / `allowSavedViews === null` в `ApiConfig`
 * означают «резолвить из ресурса» и должны быть раскрыты в
 * {@see ListResource::resolveApiConfig()} ДО вызова парсера. Если null
 * прорвался до `parse()` — это контракт-bug вызывающего кода, парсер валит
 * `LogicException`, а не silent-fallback в `[]`.
 */
final class ApiQueryParser
{
    public const KNOWN_INCLUDES = ['data', 'page', 'summary', 'savedViews', 'capabilities', 'schema'];

    public function parse(Request $request, ApiConfig $config, ListResource $resource): ParsedApiQuery
    {
        $allowFields = $this->assertAllowFieldsResolved($config);
        $allowSavedViews = $this->assertAllowSavedViewsResolved($config);

        $source = $this->resolveSource($request);

        $includes = $this->parseIncludes($source, $config);
        $fields = $this->parseFields($source, $allowFields);
        [$format, $perFieldFormats] = $this->parseFormat($source, $config, $allowFields);
        $perPage = $this->parsePerPage($source, $config);
        $page = $this->parsePage($source);

        $query = new Query;
        $query->search = $this->parseSearch($source);
        $query->searchableColumns = $resource->searchable();

        [$query->sortField, $query->sortDirection] = $this->parseSort($source, $allowFields, $resource);

        $savedViewKey = $this->parseSavedView($source, $allowSavedViews);
        $query->savedViewKey = $savedViewKey;

        $userFilters = $this->parseFilters($source, $allowFields, $resource);
        $savedViewFilters = $this->resolveSavedViewConditions($savedViewKey, $resource);

        $qbRoot = $this->extractQbTree($request, $source, $resource);
        $merged = $this->mergeFlatIntoQbRoot($qbRoot, $userFilters, $savedViewFilters);

        if ($merged !== null) {
            $query->qbRoot = $merged;
            $query->conditions = [];
        } else {
            $query->qbRoot = null;
            $query->conditions = array_values(array_merge($savedViewFilters, $userFilters));
        }

        return new ParsedApiQuery($query, $includes, $fields, $format, $perPage, $page, $perFieldFormats);
    }

    /**
     * Источник пар «ключ → значение» для всего парсинга. GET-запросы читают из
     * query-string, POST с `application/json` — из тела (Laravel сам декодит JSON
     * в массив). Любой другой POST-Content-Type → 400 MALFORMED_QUERY.
     *
     * @return array<int|string, mixed>
     */
    private function resolveSource(Request $request): array
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'GET' || $method === 'HEAD') {
            /** @var array<string, mixed> $source */
            $source = $request->query();

            return $source;
        }

        if ($method !== 'POST') {
            // PendingTablesApiResource регистрирует только GET и POST на индекс-URL —
            // дойти сюда можно только если кто-то вручную добавил роут другого
            // verb'а на этот же контроллер. Это контракт-нарушение, не пользовательская
            // ошибка, поэтому LogicException вместо 4xx envelope'а.
            throw new LogicException(
                "ApiQueryParser supports only GET and POST, got '{$method}'. "
                .'PendingTablesApiResource must not register additional HTTP methods on the index URL.',
            );
        }

        if (! $request->isJson()) {
            throw new ApiValidationException(
                ApiErrorCode::MalformedQuery,
                "POST body must use 'application/json' Content-Type.",
                [
                    'reason' => 'unsupported_media_type',
                    'received' => $request->header('Content-Type'),
                ],
            );
        }

        return $request->json()->all();
    }

    /**
     * @return array<int, string>
     */
    private function assertAllowFieldsResolved(ApiConfig $config): array
    {
        $allowFields = $config->getAllowFields();
        if ($allowFields === null) {
            throw new LogicException(
                'ApiConfig::allowFields is unresolved at ApiQueryParser. '
                .'ListResource::resolveApiConfig() must fill the sentinel from fieldsMemo() before parsing.',
            );
        }

        return $allowFields;
    }

    /**
     * @return array<int, string>
     */
    private function assertAllowSavedViewsResolved(ApiConfig $config): array
    {
        $allowSavedViews = $config->getAllowSavedViews();
        if ($allowSavedViews === null) {
            throw new LogicException(
                'ApiConfig::allowSavedViews is unresolved at ApiQueryParser. '
                .'ListResource::resolveApiConfig() must fill the sentinel from savedViewsMemo() before parsing.',
            );
        }

        return $allowSavedViews;
    }

    /**
     * Унифицированный CSV-разбор для `?include` / `?fields` (и любых других
     * CSV-or-array параметров). Возвращает `null`, если значения нет / оно
     * пустое — каждый caller решает, что использовать как default.
     *
     * @return array<int, string>|null
     */
    private function parseCsvLike(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $values = array_values(array_filter(array_map(
                static fn ($v) => is_string($v) ? trim($v) : '',
                $raw,
            ), static fn (string $s) => $s !== ''));

            return $values === [] ? null : $values;
        }

        if (is_string($raw) && $raw !== '') {
            $values = array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                static fn (string $s) => $s !== '',
            ));

            return $values === [] ? null : $values;
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $source
     * @return array<int, string>
     */
    private function parseIncludes(array $source, ApiConfig $config): array
    {
        $requested = $this->parseCsvLike($source['include'] ?? null);
        if ($requested === null) {
            return $config->getDefaultIncludes();
        }

        $known = [];
        foreach ($requested as $block) {
            if (! in_array($block, self::KNOWN_INCLUDES, true)) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Unknown include block '{$block}'.",
                    ['include' => $block, 'allowed' => self::KNOWN_INCLUDES],
                );
            }
            $known[] = $block;
        }

        // data/page всегда присутствуют (даже если не запрошены).
        if (! in_array('data', $known, true)) {
            $known[] = 'data';
        }
        if (! in_array('page', $known, true)) {
            $known[] = 'page';
        }

        return array_values(array_unique($known));
    }

    /**
     * @param  array<int|string, mixed>  $source
     * @param  array<int, string>  $allowFields
     * @return array<int, string>
     */
    private function parseFields(array $source, array $allowFields): array
    {
        $requested = $this->parseCsvLike($source['fields'] ?? null);
        if ($requested === null) {
            return array_values($allowFields);
        }

        foreach ($requested as $name) {
            if (! in_array($name, $allowFields, true)) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Field '{$name}' is not allowed by API config.",
                    ['field' => $name, 'allowed' => array_values($allowFields)],
                );
            }
        }

        return $requested;
    }

    /**
     * Разбор `?format=` в base mode + per-field overrides.
     *
     * Принимает string (`?format=both`, `body.format = "both"`) или array
     * (`?format[total]=both`, `body.format = {"total":"both"}`). В array-форме
     * спец-ключ `*` задаёт base mode для не-перечисленных полей; без него
     * base = `ApiConfig::getDefaultFormat()`. Все поля в array-форме
     * валидируются по `$allowFields` (whitelist), все mode — по
     * {@see FormatMode}; невалидные значения → 422 VALIDATION_FAILED.
     *
     * @param  array<int|string, mixed>  $source
     * @param  array<int, string>  $allowFields
     * @return array{0: FormatMode, 1: array<string, FormatMode>}
     */
    private function parseFormat(array $source, ApiConfig $config, array $allowFields): array
    {
        $raw = $source['format'] ?? null;

        if ($raw === null || $raw === '') {
            return [$config->getDefaultFormat(), []];
        }

        if (is_string($raw)) {
            $mode = FormatMode::tryFrom($raw);
            if ($mode === null) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Format '{$raw}' is not supported. Expected one of: raw, formatted, both.",
                    ['format' => $raw, 'allowed' => ['raw', 'formatted', 'both']],
                );
            }

            return [$mode, []];
        }

        if (! is_array($raw)) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                'format must be a string or an object/array of {field: mode}.',
                ['format' => $raw, 'allowed' => ['raw', 'formatted', 'both']],
            );
        }

        $base = $config->getDefaultFormat();

        if (array_key_exists('*', $raw)) {
            $baseRaw = $raw['*'];
            unset($raw['*']);

            $baseMode = is_string($baseRaw) ? FormatMode::tryFrom($baseRaw) : null;
            if ($baseMode === null) {
                $baseRawForMessage = is_scalar($baseRaw) ? (string) $baseRaw : '<non-scalar>';
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Format '{$baseRawForMessage}' is not supported. Expected one of: raw, formatted, both.",
                    [
                        'format' => $baseRaw,
                        'field' => '*',
                        'allowed' => ['raw', 'formatted', 'both'],
                    ],
                );
            }

            $base = $baseMode;
        }

        $perField = [];

        foreach ($raw as $fieldKey => $modeRaw) {
            $field = (string) $fieldKey;

            if (! in_array($field, $allowFields, true)) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Field '{$field}' is not allowed by API config.",
                    ['field' => $field, 'allowed' => array_values($allowFields)],
                );
            }

            $mode = is_string($modeRaw) ? FormatMode::tryFrom($modeRaw) : null;
            if ($mode === null) {
                $modeRawForMessage = is_scalar($modeRaw) ? (string) $modeRaw : '<non-scalar>';
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Format '{$modeRawForMessage}' is not supported. Expected one of: raw, formatted, both.",
                    [
                        'format' => $modeRaw,
                        'field' => $field,
                        'allowed' => ['raw', 'formatted', 'both'],
                    ],
                );
            }

            $perField[$field] = $mode;
        }

        return [$base, $perField];
    }

    /**
     * @param  array<int|string, mixed>  $source
     */
    private function parsePerPage(array $source, ApiConfig $config): int
    {
        $raw = $source['per_page'] ?? null;
        if ($raw === null || $raw === '') {
            return $config->getDefaultPerPage();
        }

        if (! is_numeric($raw)) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "per_page must be an integer, got '{$raw}'.",
                ['per_page' => $raw],
            );
        }

        $value = (int) $raw;
        $max = $config->getMaxPerPage();
        if ($value < 1 || $value > $max) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "per_page must be between 1 and {$max}, got {$value}.",
                ['per_page' => $value, 'min' => 1, 'max' => $max],
            );
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $source
     */
    private function parsePage(array $source): int
    {
        $raw = $source['page'] ?? null;
        if ($raw === null || $raw === '') {
            return 1;
        }

        if (! is_numeric($raw)) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "page must be an integer, got '{$raw}'.",
                ['page' => $raw],
            );
        }

        $value = (int) $raw;
        if ($value < 1) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "page must be >= 1, got {$value}.",
                ['page' => $value],
            );
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $source
     */
    private function parseSearch(array $source): ?string
    {
        $raw = $source['q'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
    }

    /**
     * @param  array<int|string, mixed>  $source
     * @param  array<int, string>  $allowFields
     * @return array{0: ?string, 1: string}
     */
    private function parseSort(array $source, array $allowFields, ListResource $resource): array
    {
        $raw = $source['sort'] ?? null;
        if (! is_string($raw) || $raw === '') {
            $default = $resource->defaultSort();
            if ($default !== null) {
                return [$default[0], $default[1]];
            }

            return [null, 'asc'];
        }

        $direction = 'asc';
        $field = $raw;
        if (str_starts_with($raw, '-')) {
            $direction = 'desc';
            $field = substr($raw, 1);
        }

        if ($field === '' || ! in_array($field, $allowFields, true)) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "Sort field '{$field}' is not allowed.",
                ['field' => $field, 'allowed' => array_values($allowFields)],
            );
        }

        return [$field, $direction];
    }

    /**
     * @param  array<int|string, mixed>  $source
     * @param  array<int, string>  $allowSavedViews
     */
    private function parseSavedView(array $source, array $allowSavedViews): ?string
    {
        $raw = $source['savedView'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (! in_array($raw, $allowSavedViews, true)) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "Saved view '{$raw}' is not allowed.",
                ['savedView' => $raw, 'allowed' => array_values($allowSavedViews)],
            );
        }

        return $raw;
    }

    /**
     * Парсинг плоских `?filter[..]` → массив `FilterCondition`.
     *
     * Формы:
     * - `?filter[status]=paid`               → `FilterCondition('status', Eq, 'paid')`
     * - `?filter[total][gte]=1000`           → `FilterCondition('total', Gte, '1000')`
     * - `?filter[status][in][]=a&[in][]=b`   → `FilterCondition('status', In, ['a','b'])`
     * - `?filter[total][between][0]=x&[1]=y` → `FilterCondition('total', Between, ['x','y'])`
     * - `?filter[verified_at][empty]=true`   → `FilterCondition('verified_at', Empty_, null)`
     * - `?filter[verified_at][not_empty]=…`  → `FilterCondition('verified_at', NotEmpty, null)`
     *
     * @param  array<int|string, mixed>  $source
     * @param  array<int, string>  $allowFields
     * @return array<int, FilterCondition>
     */
    private function parseFilters(array $source, array $allowFields, ListResource $resource): array
    {
        $raw = $source['filter'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $conditions = [];

        foreach ($raw as $fieldName => $spec) {
            $fieldName = (string) $fieldName;
            if (! in_array($fieldName, $allowFields, true)) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Field '{$fieldName}' is not allowed by API config.",
                    ['field' => $fieldName, 'allowed' => array_values($allowFields)],
                );
            }

            $field = $resource->findField($fieldName);
            if ($field === null || ! $field->isFilterable()) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Field '{$fieldName}' is not filterable.",
                    ['field' => $fieldName],
                );
            }

            $allowedOps = array_map(static fn (Operator $op) => $op->value, $field->getFilterableOperators());

            if (! is_array($spec)) {
                // ?filter[status]=paid → Eq
                $this->assertOperatorAllowed($fieldName, 'eq', $allowedOps);
                $conditions[] = new FilterCondition($fieldName, Operator::Eq, $spec);

                continue;
            }

            foreach ($spec as $opName => $value) {
                $opName = (string) $opName;
                $this->assertOperatorAllowed($fieldName, $opName, $allowedOps);
                $operator = Operator::tryFrom($opName);
                if ($operator === null) {
                    throw new ApiValidationException(
                        ApiErrorCode::ValidationFailed,
                        "Operator '{$opName}' is not a known operator.",
                        ['field' => $fieldName, 'operator' => $opName],
                    );
                }

                $normalized = $this->normalizeFilterValue($field, $operator, $value);
                $conditions[] = new FilterCondition($fieldName, $operator, $normalized);
            }
        }

        return $conditions;
    }

    /**
     * Извлечение полноценного QB-дерева из запроса.
     *
     * Источники:
     * - GET `?qb=<base64-encoded JSON>` → строка base64;
     * - POST body.qb → уже декодированный массив (Laravel сам распаковал JSON).
     *
     * Двойная подача (POST с body.qb + query-param ?qb=) → 400 MALFORMED_QUERY
     * с `details.reason = 'qb_specified_twice'`. Это страховка от случайного
     * включения qb в query одной командой и в body другой — клиенту лучше
     * получить явный отказ, чем silently отброшенное дерево.
     *
     * @param  array<int|string, mixed>  $source
     */
    private function extractQbTree(Request $request, array $source, ListResource $resource): ?AtomGroup
    {
        $isPost = strtoupper($request->getMethod()) === 'POST';

        $bodyQb = $isPost ? ($source['qb'] ?? null) : null;
        $queryQb = $request->query('qb');

        $bodyPresent = $isPost && is_array($bodyQb) && $bodyQb !== [];
        $queryPresent = is_string($queryQb) && $queryQb !== '';

        if ($isPost && $bodyPresent && $queryPresent) {
            throw new ApiValidationException(
                ApiErrorCode::MalformedQuery,
                'QB-tree is specified both in JSON body and in query string. Send it through only one channel.',
                ['reason' => 'qb_specified_twice'],
            );
        }

        try {
            if ($bodyPresent) {
                /** @var array<mixed, mixed> $bodyQb */
                $tree = QueryBuilderParser::parseArray($bodyQb, $resource, strict: true);
            } elseif ($queryPresent) {
                $tree = QueryBuilderParser::parse($queryQb, $resource, strict: true);
            } else {
                return null;
            }
        } catch (QueryBuilderValidationException $e) {
            throw $this->mapQbExceptionToApi($e);
        }

        return $tree;
    }

    /**
     * Маппинг `QueryBuilderValidationException::$kind` в `ApiErrorCode`:
     * whitelist-провалы → 422 VALIDATION_FAILED; структурные/лимитные → 400 MALFORMED_QUERY.
     */
    private function mapQbExceptionToApi(QueryBuilderValidationException $e): ApiValidationException
    {
        $validationKinds = [
            QueryBuilderValidationException::KIND_UNKNOWN_FIELD,
            QueryBuilderValidationException::KIND_OPERATOR_NOT_ALLOWED,
            QueryBuilderValidationException::KIND_EMPTY_VALUE,
            QueryBuilderValidationException::KIND_VALUE_NOT_NORMALIZABLE,
        ];

        $details = ['kind' => $e->kind];
        if ($e->field !== null) {
            $details['field'] = $e->field;
        }
        if ($e->operator !== null) {
            $details['operator'] = $e->operator;
        }
        foreach ($e->details as $k => $v) {
            if (! array_key_exists($k, $details)) {
                $details[$k] = $v;
            }
        }

        $code = in_array($e->kind, $validationKinds, true)
            ? ApiErrorCode::ValidationFailed
            : ApiErrorCode::MalformedQuery;

        return new ApiValidationException($code, $e->getMessage(), $details);
    }

    /**
     * Сливает qb-tree, плоские `?filter[..]` и savedView-conditions в один AND-root.
     *
     * Логика:
     * - `$root === null` → qb-tree не пришёл, плоские остаются в `Query::$conditions`,
     *   метод возвращает `null`.
     * - `$root === AtomCondition` (одиночный atom) или `$root === AtomGroup(AND, not=false)` →
     *   расширяем `children` дополнительными `AtomCondition`-конверсиями из flat-filters
     *   и savedView-conditions; возвращаем новый `AtomGroup(AND, not=false, [...])`.
     * - `$root === AtomGroup(OR, ...)` или `$root === AtomGroup(*, not=true)` →
     *   оборачиваем root в новый AND-узел: `new AtomGroup('AND', false, [$root, ...flat, ...savedView])`.
     *   Это сохраняет семантику исходного «top-level OR / NOT» как одного узла и AND-merge'ит сверху.
     *
     * @param  array<int, FilterCondition>  $userFilters
     * @param  array<int, FilterCondition>  $savedViewFilters
     */
    private function mergeFlatIntoQbRoot(
        ?AtomGroup $root,
        array $userFilters,
        array $savedViewFilters,
    ): ?AtomGroup {
        if ($root === null) {
            return null;
        }

        // Нечего мерджить — возвращаем root без изменений (избегаем degenerate
        // AND-обёртки `AND([OR(...)])` для top-level OR без flat/savedView).
        if ($userFilters === [] && $savedViewFilters === []) {
            return $root;
        }

        $atomsFromSavedView = array_values(array_map(
            static fn (FilterCondition $f) => AtomCondition::fromFilter($f),
            $savedViewFilters,
        ));
        $atomsFromFlats = array_values(array_map(
            static fn (FilterCondition $f) => AtomCondition::fromFilter($f),
            $userFilters,
        ));

        $isPlainAnd = $root->op === 'AND' && $root->not === false;

        if ($isPlainAnd) {
            return $root->withChildren(array_merge($root->children, $atomsFromSavedView, $atomsFromFlats));
        }

        return new AtomGroup('AND', false, array_merge([$root], $atomsFromSavedView, $atomsFromFlats));
    }

    /**
     * @param  array<int, string>  $allowedOps
     */
    private function assertOperatorAllowed(string $field, string $operator, array $allowedOps): void
    {
        if (! in_array($operator, $allowedOps, true)) {
            throw new ApiValidationException(
                ApiErrorCode::ValidationFailed,
                "Operator '{$operator}' is not allowed for field '{$field}'.",
                ['field' => $field, 'operator' => $operator, 'allowed' => array_values($allowedOps)],
            );
        }
    }

    private function normalizeFilterValue(Field $field, Operator $operator, mixed $value): mixed
    {
        if ($operator === Operator::Empty_ || $operator === Operator::NotEmpty) {
            return null;
        }

        if ($operator === Operator::In || $operator === Operator::NotIn) {
            if (! is_array($value)) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Operator '{$operator->value}' requires an array value for field '{$field->name}'.",
                    ['field' => $field->name, 'operator' => $operator->value],
                );
            }

            return array_values($value);
        }

        if ($operator === Operator::Between || $operator === Operator::NotBetween) {
            if (! is_array($value) || count($value) !== 2) {
                throw new ApiValidationException(
                    ApiErrorCode::ValidationFailed,
                    "Operator '{$operator->value}' requires exactly two values for field '{$field->name}'.",
                    ['field' => $field->name, 'operator' => $operator->value],
                );
            }

            return array_values($value);
        }

        return $value;
    }

    /**
     * @return array<int, FilterCondition>
     */
    private function resolveSavedViewConditions(?string $key, ListResource $resource): array
    {
        if ($key === null) {
            return [];
        }

        foreach ($resource->savedViewsMemo() as $view) {
            if ($view->key === $key) {
                return $view->conditions;
            }
        }

        return [];
    }
}
