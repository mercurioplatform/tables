<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\BuiltinFilterApplier;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\View\SavedView;

final class SavedViewCountsCalculator
{
    /**
     * @return array<string, int>
     */
    public function counts(ListResource $resource): array
    {
        $views = $resource->savedViewsMemo();
        if ($views === []) {
            return [];
        }

        $source = $resource->resolveSource();

        if ($source instanceof EloquentSource) {
            return $this->countsForEloquent($resource, $source, $views);
        }

        return $this->countsForGenericSource($resource, $source, $views);
    }

    /**
     * Single SQL-roundtrip через UNION subqueries — работает только для
     * EloquentSource (требует `getBuilder()->toSql()` + `getBindings()`).
     *
     * @param  array<int, SavedView>  $views
     * @return array<string, int>
     */
    private function countsForEloquent(
        ListResource $resource,
        EloquentSource $source,
        array $views,
    ): array {
        $base = $source->getBuilder();
        $fieldMap = null;

        $selects = [];
        $bindings = [];
        $aliasMap = [];

        foreach ($views as $idx => $view) {
            if ($view->sourceClosure !== null) {
                // sourceClosure работает на уровне Source-instance после
                // withQuery — здесь, в SQL-UNION-композиции, его применить
                // нельзя. Соответствующий counts[view] просто не появится.
                Log::debug('tables.saved_view.counts_unsupported_source_closure', [
                    'resource' => $resource->key(),
                    'view' => $view->key,
                ]);

                continue;
            }

            $cloned = $view->applyForCount(clone $base);

            if ($view->conditions !== []) {
                if ($fieldMap === null) {
                    $fieldMap = $this->buildFieldMap($resource);
                }
                foreach ($view->conditions as $cond) {
                    $field = $fieldMap[$cond->field] ?? null;
                    if ($field !== null) {
                        FilterApplier::apply($cloned, $field, $cond);
                    } else {
                        BuiltinFilterApplier::apply($cloned, $cond->field, $cond->operator, $cond->value);
                    }
                }
            }

            $sql = $cloned->getQuery()->toSql();
            $alias = 'cnt_'.$idx;
            $aliasMap[$alias] = $view->key;
            $selects[] = '(SELECT COUNT(*) FROM ('.$sql.') ___sv_'.$idx.') AS '.$alias;
            foreach ($cloned->getBindings() as $b) {
                $bindings[] = $b;
            }
        }

        if ($selects === []) {
            return [];
        }

        $finalSql = 'SELECT '.implode(', ', $selects);
        $row = (array) DB::selectOne($finalSql, $bindings);

        $result = [];
        foreach ($aliasMap as $alias => $viewKey) {
            $result[$viewKey] = (int) ($row[$alias] ?? 0);
        }

        return $result;
    }

    /**
     * Универсальный путь для не-Eloquent Source-драйверов (ArraySource в Phase 3,
     * HttpSource в Phase 5, FileSource в Phase 6). N+1 проходов по
     * `$source->withQuery($svQuery)->count()` — приемлемо для in-memory
     * use-case (справочники <10K rows, <20 saved views).
     *
     * `view->scope` (`string` model-scope **или** `Closure(Builder)`) и
     * `view->countQueryCallback` — Eloquent-only API; на non-Eloquent
     * source'ах оба варианта пропускаются с WARN.
     *
     * @param  array<int, SavedView>  $views
     * @return array<string, int>
     */
    private function countsForGenericSource(
        ListResource $resource,
        Source $source,
        array $views,
    ): array {
        $result = [];
        $warnedScope = [];
        $warnedCountCallback = [];

        foreach ($views as $view) {
            if ($view->scope !== null && ! isset($warnedScope[$view->key])) {
                Log::warning('tables.saved_view.scope_unsupported_on_non_eloquent', [
                    'resource' => $resource->key(),
                    'view' => $view->key,
                    'source' => $source::class,
                    'reason' => 'SavedView::scope accepts Eloquent\\Builder; not applicable on non-Eloquent source. Use SavedView::conditions() or SavedView::sourceClosure() instead.',
                ]);
                $warnedScope[$view->key] = true;
            }

            if ($view->getCountQueryCallback() !== null && ! isset($warnedCountCallback[$view->key])) {
                Log::warning('tables.saved_view.count_callback_unsupported_on_non_eloquent', [
                    'resource' => $resource->key(),
                    'view' => $view->key,
                    'source' => $source::class,
                    'reason' => 'SavedView::countWith() callback receives Eloquent\\Builder; not applicable on non-Eloquent source.',
                ]);
                $warnedCountCallback[$view->key] = true;
            }

            $svQuery = new Query;
            $svQuery->savedViewKey = $view->key;
            $svQuery->conditions = $view->conditions;

            $applied = $source->withQuery($svQuery);
            if ($view->sourceClosure !== null) {
                $applied = ($view->sourceClosure)($applied);
            }

            $count = $applied->count();
            $result[$view->key] = $count ?? 0;
        }

        Log::debug('tables.saved_view.counts_generic_source', [
            'resource' => $resource->key(),
            'source' => $source::class,
            'view_count' => count($views),
        ]);

        return $result;
    }

    /**
     * @return array<string, Field>
     */
    private function buildFieldMap(ListResource $resource): array
    {
        $map = [];
        foreach ($resource->fieldsMemo() as $field) {
            $map[$field->name] = $field;
        }

        return $map;
    }
}
