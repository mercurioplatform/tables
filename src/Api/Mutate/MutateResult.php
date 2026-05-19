<?php

namespace Mercurio\Tables\Api\Mutate;

use Mercurio\Tables\Api\MutateRenderer;

/**
 * Маркер-интерфейс для трёх value object'ов pure-mutate-примитивов
 * ({@see CellMutateResult}, {@see RowMutateResult}, {@see BulkMutateResult}).
 *
 * Возвращается из чистых методов хэндлеров (`applyAsArray()` / `applyAsResult()`)
 * и передаётся в {@see MutateRenderer} для сериализации в
 * JSON-envelope. PHP не имеет sealed-классов, но три immutable readonly-класса
 * под одним interface — близкая аппроксимация и удобный type-hint для
 * `MutateRenderer::render(MutateResult)`.
 */
interface MutateResult {}
