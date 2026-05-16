<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\BuiltinFilterApplier;
use Mercurio\Tables\Filter\FilterApplier;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\EloquentSource;

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
        if (! $source instanceof EloquentSource) {
            // Phase 1: подсчёт saved-view counts реализован SQL-объединением
            // (UNION subqueries), это работает только для EloquentSource.
            // Не-Eloquent Source-драйверы получат counts в более поздних фазах.
            Log::debug('tables.saved_views.counts_unsupported', [
                'resource' => $resource->key(),
                'source' => $source::class,
            ]);

            return [];
        }

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
