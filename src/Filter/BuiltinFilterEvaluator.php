<?php

namespace Mercurio\Tables\Filter;

use Mercurio\Tables\Filter\Qb\AtomEvaluator;
use Mercurio\Tables\Source\ArraySource;

/**
 * In-memory зеркало {@see BuiltinFilterApplier} — evaluator одного оператора
 * для одной row-value пары без зависимости от Eloquent / DB.
 *
 * Используется:
 * - {@see AtomEvaluator} — для AtomCondition внутри
 *   `?qb=` AST на in-memory sources (ArraySource, и в будущих фазах
 *   HttpSource client-side fallback + FileSource).
 * - {@see ArraySource::withQuery()} — для chip-фильтров
 *   из `Query::$conditions`.
 *
 * Разница семантики vs `BuiltinFilterApplier` (SQL `LIKE`):
 * - `Contains`/`StartsWith`/`EndsWith` (+ их `Not*`-пары) сравнивают строки
 *   как **буквальный substring** через `mb_stripos`. SQL-метасимволы (`%`, `_`)
 *   в expected-value трактуются буквально. Это отличается от
 *   `BuiltinFilterApplier` (SQL-режим), где те же метасимволы экранируются
 *   через `escapeLike()`, чтобы их можно было искать как обычные символы в
 *   данных. В практическом плане: на ArraySource поиск `"%"` найдёт строки,
 *   содержащие литерал `%`; в SQL-режиме — то же поведение благодаря
 *   `escapeLike()`. Разница проявляется только в edge-case'ах с regexp-стилем
 *   wildcard'ов, которые в built-in-операторах изначально не поддерживаются.
 *   Подробнее — `docs/sources.md`.
 * - Сравнения (`Eq`/`Neq`/`Gt`/`Lt`/...) выполняются через `<=>` с
 *   normalization для числовых строк, чтобы поведение совпадало с SQL `=`/`<`
 *   на колонках без strict-typing.
 */
final class BuiltinFilterEvaluator
{
    public static function matches(mixed $value, Operator $op, mixed $expected): bool
    {
        switch ($op) {
            case Operator::Eq:
                return self::compare($value, $expected) === 0;
            case Operator::Neq:
                return self::compare($value, $expected) !== 0;

            case Operator::In:
                return self::isIn($value, $expected);
            case Operator::NotIn:
                return ! self::isIn($value, $expected);

            case Operator::Contains:
                return self::stringContains($value, $expected);
            case Operator::NotContains:
                return ! self::stringContains($value, $expected);

            case Operator::StartsWith:
                return self::stringStartsWith($value, $expected);
            case Operator::NotStartsWith:
                return ! self::stringStartsWith($value, $expected);

            case Operator::EndsWith:
                return self::stringEndsWith($value, $expected);
            case Operator::NotEndsWith:
                return ! self::stringEndsWith($value, $expected);

            case Operator::Between:
                return self::between($value, $expected);
            case Operator::NotBetween:
                return ! self::between($value, $expected);

            case Operator::Empty_:
                return self::isEmpty($value);
            case Operator::NotEmpty:
                return ! self::isEmpty($value);

            case Operator::Gt:
                return self::compareOrFalse($value, $expected, fn (int $c) => $c > 0);
            case Operator::Lt:
                return self::compareOrFalse($value, $expected, fn (int $c) => $c < 0);
            case Operator::Gte:
                return self::compareOrFalse($value, $expected, fn (int $c) => $c >= 0);
            case Operator::Lte:
                return self::compareOrFalse($value, $expected, fn (int $c) => $c <= 0);
        }
    }

    /**
     * Лояльное сравнение row-value и expected с numeric-aware normalization,
     * имитирующее поведение `WHERE col = :v` на MySQL/Postgres без strict-cast.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return $a === $b ? 0 : ($a === null ? -1 : 1);
        }

        if (is_bool($a) || is_bool($b)) {
            return (int) $a <=> (int) $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return ((float) $a) <=> ((float) $b);
        }

        return self::stringValue($a) <=> self::stringValue($b);
    }

    private static function compareOrFalse(mixed $a, mixed $b, \Closure $predicate): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return $predicate(self::compare($a, $b));
    }

    private static function isIn(mixed $value, mixed $expected): bool
    {
        $list = is_array($expected) ? $expected : [$expected];

        foreach ($list as $candidate) {
            if (self::compare($value, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function stringContains(mixed $value, mixed $expected): bool
    {
        $needle = self::stringValue($expected);
        if ($needle === '') {
            return true;
        }

        $haystack = self::stringValue($value);

        return mb_stripos($haystack, $needle) !== false;
    }

    private static function stringStartsWith(mixed $value, mixed $expected): bool
    {
        $needle = self::stringValue($expected);
        if ($needle === '') {
            return true;
        }

        $haystack = self::stringValue($value);

        return mb_stripos($haystack, $needle) === 0;
    }

    private static function stringEndsWith(mixed $value, mixed $expected): bool
    {
        $needle = self::stringValue($expected);
        if ($needle === '') {
            return true;
        }

        $haystack = mb_strtolower(self::stringValue($value));
        $needle = mb_strtolower($needle);
        $len = mb_strlen($needle);
        $haystackLen = mb_strlen($haystack);

        if ($haystackLen < $len) {
            return false;
        }

        return mb_substr($haystack, $haystackLen - $len) === $needle;
    }

    private static function between(mixed $value, mixed $expected): bool
    {
        if ($value === null) {
            return false;
        }

        $min = null;
        $max = null;
        if (is_array($expected)) {
            $min = $expected[0] ?? null;
            $max = $expected[1] ?? null;
        }

        if ($min !== null && self::compare($value, $min) < 0) {
            return false;
        }
        if ($max !== null && self::compare($value, $max) > 0) {
            return false;
        }

        // Если обе границы null — поведение совпадает с SQL: WHERE-block пустой,
        // фильтр не отсекает ничего.
        return true;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function stringValue(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        if ($v instanceof \Stringable) {
            return (string) $v;
        }

        return '';
    }
}
