<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;
use RuntimeException;

/**
 * Фикстура, у которой `resolveSource()` бросает Throwable.
 * Используется для проверки catch-all'а в JsonApiSchemaController.
 */
final class BrokenSourceResource extends ListResource
{
    public function key(): string
    {
        return 'broken_source';
    }

    public function source(): ?Source
    {
        throw new RuntimeException('Source intentionally broken for schema tests.');
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id'),
        ];
    }
}
