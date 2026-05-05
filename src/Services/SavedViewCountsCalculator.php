<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\ListResource;

final class SavedViewCountsCalculator
{
    /**
     * @return array<string, int>
     */
    public function counts(ListResource $resource): array
    {
        $views = $resource->savedViews();
        if ($views === []) {
            return [];
        }

        $base = $resource->query();

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

        Log::debug('tables.savedviews.counts', [
            'resource' => $resource->key(),
            'views' => count($views),
            'counts' => $result,
        ]);

        return $result;
    }
}
