<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $selects = [];
        $bindings = [];
        $aliasMap = [];

        foreach ($views as $idx => $view) {
            $cloned = $view->applyForCount(clone $base);
            $sql = $cloned->getQuery()->toSql();
            $alias = 'cnt_'.$idx;
            $aliasMap[$alias] = $view->key;
            $selects[] = '(SELECT COUNT(*) FROM ('.$sql.') ___sv_'.$idx.') AS '.$alias;
            foreach ($cloned->getBindings() as $b) {
                $bindings[] = $b;
            }
        }

        $finalSql = 'SELECT '.implode(', ', $selects);
        $row = (array) DB::selectOne($finalSql, $bindings);

        $result = [];
        foreach ($aliasMap as $alias => $viewKey) {
            $result[$viewKey] = (int) ($row[$alias] ?? 0);
        }

        return $result;
    }
}
