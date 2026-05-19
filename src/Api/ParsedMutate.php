<?php

namespace Mercurio\Tables\Api;

/**
 * VO для распарсенного body эндпоинта `POST /{uri}/mutate`.
 *
 * Заполняется {@see MutateBodyParser::parse()}. Per-op-поля заполняются
 * по дискриминатору `$op`: для `cell` — `id`/`field`/`value`; для `row` —
 * `id`/`action`/`payload`; для `bulk` — `action`/`ids`/`payload`.
 */
final readonly class ParsedMutate
{
    /**
     * @param  'cell'|'row'|'bulk'  $op
     * @param  array<int, int|string>|null  $ids
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $includes
     */
    public function __construct(
        public string $op,
        public int|string|null $id = null,
        public ?string $field = null,
        public mixed $value = null,
        public ?string $action = null,
        public ?array $ids = null,
        public array $payload = [],
        public array $includes = [],
    ) {}

    public function wantsInclude(string $name): bool
    {
        return in_array($name, $this->includes, true);
    }
}
