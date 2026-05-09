<?php

namespace Mercurio\Tables\Support;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: none — used internally by package services.
 */
class Pluralizer
{
    public static function ru(int $n, string $many, string $one, string $few): string
    {
        $abs = abs($n);
        $mod10 = $abs % 10;
        $mod100 = $abs % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }
}
