<?php

namespace Mercurio\Tables\Export;

use Mercurio\Tables\Field\Field;

final readonly class ExportRequest
{
    /**
     * @param  array<int, Field>  $columns
     */
    public function __construct(
        public string $filename,
        public string $delimiter,
        public string $enclosure,
        public string $escape,
        public bool $bom,
        public int $chunkSize,
        public bool $logChunks,
        public array $columns,
    ) {}
}
