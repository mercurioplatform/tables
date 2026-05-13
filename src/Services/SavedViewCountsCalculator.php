<?php

namespace Mercurio\Tables\Services;

use Illuminate\Support\Facades\DB;
use Mercurio\Tables\ListResource;

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

        return $result;
    }
}
