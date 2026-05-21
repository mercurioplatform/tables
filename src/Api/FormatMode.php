<?php

namespace Mercurio\Tables\Api;

use Mercurio\Tables\Field\Field;

/**
 * Режим сериализации поля в JSON-envelope.
 *
 * - {@see self::Raw}      — `"total": 100` (scalar из Source).
 * - {@see self::Formatted} — `"total": "1 290,50 ₽"` (plain-text через
 *   {@see Field::exportValue()}).
 * - {@see self::Both}     — `"total": {"raw": 100, "display": "1 290,50 ₽", "tone": null}`.
 *
 * Управляется глобальным `?format=raw|formatted|both` или per-field overrides
 * `?format[total]=both&format[status]=raw` (см. `docs/json-api.md` → `?format=`).
 *
 * Парсинг — встроенный `enum::tryFrom()` / `enum::from()`; никаких тонких
 * обёрток сверху.
 */
enum FormatMode: string
{
    case Raw = 'raw';
    case Formatted = 'formatted';
    case Both = 'both';
}
