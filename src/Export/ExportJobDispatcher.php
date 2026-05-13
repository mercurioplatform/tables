<?php

namespace Mercurio\Tables\Export;

interface ExportJobDispatcher
{
    /**
     * Dispatch an asynchronous export.
     *
     * @param  string  $resourceClass  FQCN of ListResource
     * @param  array<string,mixed>  $queryParams  raw request query (q, view, f, qb, sort, dir, columns)
     * @param  int  $estimatedRows  total() from paginator before chunking
     * @return string job tracker id (used in topbar notification later)
     */
    public function dispatch(
        string $resourceClass,
        array $queryParams,
        int $userId,
        int $estimatedRows,
    ): string;
}
