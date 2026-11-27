<?php

namespace App\Services\TimetableImport;

use App\Support\DayTime;

/**
 * The layout of NUML's own timetable PDFs (SE/IT/DS and CS departments, Fall 2026):
 *
 *            | Slot Time     | MONDAY          | TUESDAY | ...
 *   BSSE-B39 | 08:00 - 08:50 | Prog. Fundamentals          <- course
 *   I-A-Mor  |               | Room # 8 - Ghazali          <- room
 *  (rotated) |               | Mr. Khateeb Khan            <- teacher
 *            | 08:55 - 09:45 | ...
 *   BSSE-B39 | 08:00 - 08:50 | ...                         <- a second section, same page
 *   I-B-Mor  |
 *
 * - days across the top (later pages may leave the header out: the columns of
 *   the page before are used);
 * - a time label on the left of every row, centred on its cell;
 * - the section's name written sideways on the far left, once per block (or
 *   once per row);
 * - "FREE SLOT" / "FYP" cells, sometimes stamped diagonally, are not classes.
 *
 * Each section becomes one Sheet in the FLAT form TimetableParser already reads
 * (Day | Time | Course | Teacher | Room, one row per class).
 */
final class PdfDayGridReader
{
    private const TIME_LABEL = '/^\s*\d{1,2}[:.]\d{2}\s*[-\x{2013}]\s*\d{1,2}[:.]\d{2}\s*$/u';

    private const TEACHER = '/^(mr|mrs|ms|miss|dr|prof|engr|sir)\b\.?/i';

    private const ROOM = '/\b(room|lab|block|ghazali|rushd|hall|floor|basement)\b|#/i';

    /**
     * @param  list<array{x: float, y: float, text: string, size: float, a: float, b: float}>  $items
     * @param  array<string, float>|null  $days  the day columns of an earlier page (day => centre x)
     * @return array{0: list<Sheet>, 1: array<string, float>}|null  the sheets and this page's day
     *                                                              columns; null if the page isn't this layout
     */
    public static function read(array $items, ?array $days, int $pageNumber): ?array
    {
        $upright = array_values(array_filter($items, fn ($i) => abs($i['b']) < 0.1 && $i['a'] > 0.9));
        $sideways = array_values(array_filter($items, fn ($i) => abs($i['a']) < 0.1 && abs(abs($i['b']) - 1) < 0.1));
        // Diagonal text (a and b both about 0.7) is the "FREE SLOT" stamp: ignored.

        [$headerDays, $headerY] = self::dayHeader($upright);
        $days = $headerDays ?? $days;
        if ($days === null) {
            return null;
        }
        $headerY ??= INF;
        $firstDayX = min($days);

        // Row labels: a cell that is only a time range, left of the first day, below the header.
        $anchors = [];
        foreach ($upright as $i) {
            if ($i['y'] < $headerY && self::centre($i) < $firstDayX && preg_match(self::TIME_LABEL, $i['text'])
                && ($span = DayTime::parseTimeRange($i['text']))) {
                $anchors[round($i['y']).'|'.$i['text']] = ['y' => $i['y'], 'x' => self::centre($i), 'text' => trim($i['text']), 'span' => $span];
            }
        }
        $anchors = array_values($anchors);
        if (count($anchors) < 2) {
            return null;
        }

        $timeX = self::median(array_column($anchors, 'x'));
        $columns = ['' => $timeX, ...$days];   // '' = the time column: text there is not a class
        $rowGap = self::rowGap($anchors);

        // Cell text: upright, in a day column, near a row, and not a big title.
        $candidates = array_filter($upright, fn ($i) => $i['y'] < $headerY - 1);
        $cellSize = self::median(array_column($candidates, 'size') ?: [8.0]);
        $cells = [];   // anchor index => day => list of [y, x, text]
        foreach ($candidates as $i) {
            if ($i['size'] > $cellSize * 1.2 || preg_match(self::TIME_LABEL, $i['text'])) {
                continue;
            }
            $day = self::nearestKey($columns, self::centre($i));
            if ($day === '') {
                continue;
            }
            $row = self::nearestIndex($anchors, $i['y']);
            if (abs($anchors[$row]['y'] - $i['y']) > $rowGap * 0.6) {
                continue;
            }
            $cells[$row][$day][] = [$i['y'], $i['x'], $i['text']];
        }

        // Each row belongs to the nearest section label.
        $labels = self::sectionLabels($sideways);
        $issues = [];
        if ($labels === []) {
            $labels = [['y' => 0.0, 'name' => "Page $pageNumber"]];
            $issues[] = new ImportIssue("Page $pageNumber", 0,
                "No section name found beside the table; the classes were put in section 'Page $pageNumber'", 'warning');
        }

        $sections = [];   // name => slot label => day => cell text
        foreach ($anchors as $r => $anchor) {
            $name = $labels[self::nearestIndex($labels, $anchor['y'])]['name'];
            $sections[$name][$anchor['text']] ??= [];
            foreach ($cells[$r] ?? [] as $day => $parts) {
                if ($cell = self::cellText($parts)) {
                    $sections[$name][$anchor['text']][$day] = $cell;
                }
            }
        }

        // One FLAT table per section (works even for a section with a single row).
        $sheets = [];
        foreach ($sections as $name => $slots) {
            uksort($slots, fn ($a, $b) => DayTime::parseTimeRange($a) <=> DayTime::parseTimeRange($b));
            $table = [['Day', 'Time', 'Course', 'Teacher', 'Room']];
            foreach (array_keys($days) as $day) {
                foreach ($slots as $slot => $byDay) {
                    if (isset($byDay[$day])) {
                        $table[] = [$day, $slot, ...$byDay[$day]];
                    }
                }
            }
            $sheets[] = new Sheet($name, $table, $issues);
            $issues = [];
        }

        return [$sheets, $days];
    }

    /** @return array{0: array<string, float>|null, 1: float|null} day => centre x, and the header's y */
    private static function dayHeader(array $upright): array
    {
        $byLine = [];
        foreach ($upright as $i) {
            $day = DayTime::normalizeDay($i['text']);
            if ($day !== null && mb_strlen(trim($i['text'])) <= 10) {
                $byLine[(string) round($i['y'] / 3)][$day] = ['x' => self::centre($i), 'y' => $i['y']];
            }
        }
        foreach ($byLine as $found) {
            if (count($found) >= 3) {
                uasort($found, fn ($a, $b) => $a['x'] <=> $b['x']);

                return [array_map(fn ($d) => $d['x'], $found), min(array_column($found, 'y'))];
            }
        }

        return [null, null];
    }

    /**
     * Sideways text -> section names with the y of their middle. Pieces beside
     * each other ("BSSE - B39" and "I-A-Mor") are one name; repeats are dropped.
     *
     * @return list<array{y: float, name: string}>
     */
    private static function sectionLabels(array $sideways): array
    {
        $pieces = [];
        foreach ($sideways as $i) {
            $text = preg_replace('/\s+/', ' ', trim($i['text']));
            if ($text === '' || DayTime::normalizeDay($text) !== null) {
                continue;
            }
            // Sideways text runs up (b = 1) or down (b = -1) from its start point.
            $mid = $i['y'] + ($i['b'] > 0 ? 1 : -1) * mb_strlen($text) * $i['size'] * 0.25;
            $pieces[round($i['x']).'|'.round($mid).'|'.$text] = ['x' => $i['x'], 'y' => $mid, 'text' => $text];
        }
        usort($pieces, fn ($p, $q) => $q['y'] <=> $p['y']);

        $labels = [];
        foreach ($pieces as $p) {
            $last = array_key_last($labels);
            if ($last !== null && abs($labels[$last]['y'] - $p['y']) < 30) {
                if (! in_array($p['text'], array_column($labels[$last]['pieces'], 'text'), true)) {
                    $labels[$last]['pieces'][] = $p;
                }

                continue;
            }
            $labels[] = ['y' => $p['y'], 'pieces' => [$p]];
        }

        return array_map(function ($l) {
            usort($l['pieces'], fn ($p, $q) => $p['x'] <=> $q['x']);

            return ['y' => $l['y'], 'name' => mb_substr(implode(' ', array_column($l['pieces'], 'text')), 0, 50)];
        }, $labels);
    }

    /**
     * A cell's lines (top first) -> [course, teacher, room]; null for an empty cell.
     * NUML writes Course / Room / Teacher, and long titles or rooms wrap onto
     * extra lines: lines before the room belong to the course, lines after it to
     * the room, and a line starting "Mr." / "Ms." / "Dr." is the teacher.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function cellText(array $parts): ?array
    {
        usort($parts, fn ($p, $q) => [$q[0], $p[1]] <=> [$p[0], $q[1]]);
        $lines = [];
        foreach ($parts as [$y, , $text]) {
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last]['y'] - $y) < 2) {
                $lines[$last]['text'] .= ' '.$text;
            } else {
                $lines[] = ['y' => $y, 'text' => $text];
            }
        }
        $lines = array_values(array_filter(
            array_map(fn ($l) => trim(preg_replace('/\s+/', ' ', $l['text'])), $lines),
            fn ($l) => $l !== '' && ! preg_match('/^\(.*\)$/', $l),   // "(1:45 - 4:30)" notes
        ));
        if ($lines === [] || in_array(mb_strtolower($lines[0]), TimetableParser::EMPTY_CELLS, true)) {
            return null;
        }
        // "Pre-Math I (Thursday)" in the Friday column is a note about a class held another day.
        if (preg_match('/\((mon|tues|wednes|thurs|fri|satur|sun)day\)/i', $lines[0])) {
            return null;
        }

        $course = [array_shift($lines)];
        $room = $teacher = [];
        foreach ($lines as $line) {
            if (preg_match(self::TEACHER, $line)) {
                $teacher[] = $line;
            } elseif ($room !== [] || preg_match(self::ROOM, $line)) {
                $room[] = $line;
            } elseif ($teacher === []) {
                $course[] = $line;
            }
        }

        return [implode(' ', $course), implode(' ', $teacher), implode(' ', $room)];
    }

    /** About where the text's middle is: PDFs keep no widths, so about half a font size per letter. */
    private static function centre(array $i): float
    {
        return $i['x'] + mb_strlen($i['text']) * $i['size'] * 0.25;
    }

    private static function rowGap(array $anchors): float
    {
        $ys = array_unique(array_map(fn ($a) => round($a['y']), $anchors));
        rsort($ys);
        $gaps = [];
        for ($k = 1; $k < count($ys); $k++) {
            $gaps[] = $ys[$k - 1] - $ys[$k];
        }

        return max(10.0, self::median($gaps ?: [40.0]));
    }

    private static function nearestKey(array $columns, float $x): string
    {
        $best = null;
        foreach ($columns as $key => $c) {
            if ($best === null || abs($c - $x) < abs($columns[$best] - $x)) {
                $best = $key;
            }
        }

        return (string) $best;
    }

    private static function nearestIndex(array $rows, float $y): int
    {
        $best = 0;
        foreach ($rows as $k => $row) {
            if (abs($row['y'] - $y) < abs($rows[$best]['y'] - $y)) {
                $best = $k;
            }
        }

        return $best;
    }

    private static function median(array $values): float
    {
        sort($values);

        return (float) $values[intdiv(count($values), 2)];
    }
}
