<?php

namespace App\Support;

/**
 * normalize_day and parse_time from backend/app/services/timetable_import.py,
 * used by the query filters (?day=mon, ?start=9:00). Times come back as
 * "HH:MM:SS", the form the TIME columns hold, so they compare as strings.
 */
final class DayTime
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const TIME_RE = '/(?<!\d)(\d{1,2})\s*[:.]\s*(\d{2})\s*([ap]\.?m\.?)?|(?<!\d)(\d{1,2})\s*([ap]\.?m\.?)/i';

    /** "mon", "MONDAY", "Mon." -> "Monday"; null if it isn't a day. */
    public static function normalizeDay(?string $text): ?string
    {
        $letters = preg_replace('/[^a-z]/', '', strtolower($text ?? ''));
        $prefix = substr($letters, 0, 3);

        foreach (self::DAYS as $day) {
            if (strlen($prefix) === 3 && strtolower(substr($day, 0, 3)) === $prefix) {
                return $day;
            }
        }

        return null;
    }

    /** "8:30", "08.30", "8:30 AM", "2 pm" -> "HH:MM:00"; null if it isn't a time. */
    public static function parseTime(?string $text): ?string
    {
        if (! preg_match(self::TIME_RE, $text ?? '', $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return $m[1] !== null
            ? self::toTime((int) $m[1], (int) $m[2], $m[3])
            : self::toTime((int) $m[4], 0, $m[5]);
    }

    private static function toTime(int $hour, int $minute, ?string $meridiem): ?string
    {
        $meridiem = str_replace('.', '', strtolower($meridiem ?? ''));
        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        } elseif ($meridiem === '' && $hour >= 1 && $hour <= 7) {
            $hour += 12;   // no am/pm: classes run about 08:00-19:00, so 1-7 is the afternoon
        }

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    /** Python's repr() of a string, for messages like "Unrecognised day: 'Funday'". */
    public static function repr(string $text): string
    {
        return str_contains($text, "'") && ! str_contains($text, '"') ? '"'.$text.'"' : "'".$text."'";
    }
}
