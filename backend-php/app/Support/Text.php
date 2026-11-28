<?php

namespace App\Support;

/** Small string helpers that behave like the Python code they replace. */
final class Text
{
    /** Python str.strip(): also removes no-break and ideographic spaces, which PHP's trim() keeps. */
    public static function strip(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/^[\s\p{Z}\x{85}\x{1C}-\x{1F}]+|[\s\p{Z}\x{85}\x{1C}-\x{1F}]+$/u', '', $value) ?? trim($value);
    }

    /**
     * "%value%" for a LIKE ... ESCAPE '!' search. Python escapes with a backslash;
     * '!' does the same job and also works on SQLite (which wants a one-character ESCAPE).
     */
    public static function likeContains(string $value): string
    {
        return '%'.self::likeExact($value).'%';
    }

    /**
     * The value with % and _ escaped, for a whole-value, case-insensitive match:
     * LIKE ... ESCAPE '!'. "Fall_2025" then matches only "fall_2025", not "Fall-2025".
     */
    public static function likeExact(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
