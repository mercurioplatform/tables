<?php

namespace Mercurio\Tables\Table;

use Mercurio\Tables\Field\Field;

/**
 * @internal
 *
 * Pure function: given `?sort=...&dir=...` and declared `defaultSort()`,
 * returns `{column, direction}` or null. No external dependencies.
 */
final class SortResolver
{
    /**
     * @param  array<int, Field>  $fields
     * @param  array{0: string, 1: string}|null  $defaultSort
     * @return array{column: string, direction: string}|null
     */
    public static function resolve(
        mixed $rawSort,
        mixed $rawDir,
        array $fields,
        ?array $defaultSort,
    ): ?array {
        $direction = is_string($rawDir) && strtolower($rawDir) === 'desc' ? 'desc' : 'asc';

        if (is_string($rawSort) && $rawSort !== '') {
            foreach ($fields as $field) {
                if ($field->name === $rawSort && $field->isSortable()) {
                    return ['column' => $rawSort, 'direction' => $direction];
                }
            }

            if ($defaultSort !== null) {
                $defaultDir = strtolower($defaultSort[1]) === 'desc' ? 'desc' : 'asc';

                return ['column' => $defaultSort[0], 'direction' => $defaultDir];
            }

            return null;
        }

        if ($defaultSort !== null) {
            $defaultDir = strtolower($defaultSort[1]) === 'desc' ? 'desc' : 'asc';

            return ['column' => $defaultSort[0], 'direction' => $defaultDir];
        }

        return null;
    }
}
