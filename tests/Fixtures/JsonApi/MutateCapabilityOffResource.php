<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Http\Controllers\JsonApiMutateController;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Source;

/**
 * Фикстура с `allowMutations(true)` поверх {@see ArraySource} (capabilities.mutate=false).
 * Нужна для проверки ветки capability-check в {@see JsonApiMutateController}:
 * mutate hard-gate пропускает запрос, но Source отказывает.
 */
final class MutateCapabilityOffResource extends ListResource
{
    public function key(): string
    {
        return 'mutate_capability_off';
    }

    public function source(): ?Source
    {
        return new ArraySource([['id' => 1, 'title' => 'x']], null, 'id', $this);
    }

    public function routeBaseName(): ?string
    {
        return null;
    }

    public function api(): ApiConfig
    {
        return ApiConfig::make()->allowMutations(true);
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id'),
            TextField::make('title'),
        ];
    }
}
