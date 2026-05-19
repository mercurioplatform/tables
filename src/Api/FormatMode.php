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
 */
enum FormatMode: string
{
    case Raw = 'raw';
    case Formatted = 'formatted';
    case Both = 'both';

    /**
     * Строгий парсер для URL-параметра. Кидает `\ValueError`, если строка
     * не совпадает ни с одним case'ом — вызывающая сторона ловит и
     * превращает в `ApiValidationException`.
     */
    public static function fromString(string $value): self
    {
        return self::from($value);
    }

    /**
     * Безопасный парсер: возвращает `null` если строка не совпадает.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
