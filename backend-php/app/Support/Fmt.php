<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates and times in JSON, written the way the FastAPI backend writes them,
 * so the day-8 comparison (Newman) finds no differences:
 *
 *   Fmt::date(...)      "2026-11-21"            (DATE columns)
 *   Fmt::time(...)      "09:00:00"              (TIME columns: Pydantic sends seconds too,
 *                                                even though the contract says HH:MM)
 *   Fmt::dateTime(...)  "2026-11-21T09:05:00"   (DATETIME / created_at: ISO 8601, no timezone)
 *
 * Every one returns null for null, so optional fields stay null.
 * Use them in API Resources: 'created_at' => Fmt::dateTime($this->created_at).
 */
final class Fmt
{
    public static function date(DateTimeInterface|string|null $value): ?string
    {
        return $value === null ? null : self::parse($value)->format('Y-m-d');
    }

    public static function time(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
            [$h, $m, $s] = array_map('intval', explode(':', $value.':0'));   // "9:00" -> 09:00:00

            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }

        return self::parse($value)->format('H:i:s');
    }

    public static function dateTime(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $dt = self::parse($value);

        // Python's isoformat() adds microseconds only when there are some.
        return $dt->format($dt->format('u') === '000000' ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i:s.u');
    }

    private static function parse(DateTimeInterface|string $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);
    }
}
