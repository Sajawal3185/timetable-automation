<?php

namespace App\Services\TimetableImport;

use App\Support\DayTime;

/**
 * Stage 1 and 2 of backend/app/services/timetable_import.py: tables of strings
 * -> classes (parse), then drop impossible rows and duplicates (validate).
 * No database here. The tables come from SheetReader (Excel, CSV or PDF).
 *
 * Two layouts are recognised on every sheet / PDF page:
 *   FLAT  one row per class:  Day | Time (or Start/End) | Course | Teacher | Room [| Section]
 *   GRID  days down the side and time slots across the top (or the reverse);
 *         each cell holds "Course / Teacher / Room" on separate lines.
 *
 * A real sheet that isn't recognised usually needs one of: a header spelling
 * in HEADER_ALIASES, a sheet name in SKIP_SHEETS, or a change to parseCell().
 */
final class TimetableParser
{
    /** The departments that can import (DEPARTMENT_CONFIG in Python). */
    public const DEPARTMENTS = ['AI', 'CS', 'IT', 'SE'];

    /** Sheets that never hold classes (the same for every department in Python). */
    public const SKIP_SHEETS = ['instructions', 'summary', 'rooms', 'legend'];

    public const HEADER_ALIASES = [
        'section' => ['section', 'class', 'batch', 'group', 'semester section'],
        'day' => ['day', 'days', 'weekday'],
        'time' => ['time', 'timing', 'timings', 'slot', 'time slot', 'period', 'lecture time'],
        'start_time' => ['start', 'start time', 'from', 'begin'],
        'end_time' => ['end', 'end time', 'to', 'until'],
        'course_code' => ['course code', 'code', 'subject code', 'course id'],
        'course' => ['course', 'course title', 'course name', 'subject', 'subject name', 'title'],
        'teacher' => ['teacher', 'instructor', 'faculty', 'lecturer', 'teacher name', 'instructor name', 'taught by'],
        'room' => ['room', 'venue', 'classroom', 'location', 'room no', 'room number'],
    ];

    public const EMPTY_CELLS = ['', '-', '--', 'x', 'free', 'break', 'lunch', 'prayer', 'prayer break', 'n/a', 'none'];

    private const CODE_RE = '/^\s*([A-Za-z]{2,4}\s?-?\s?\d{3,4}[A-Za-z]?)\s*[-:\x{2013}]?\s*(.*)$/su';

    private const ROOM_HINT = '/\b(room|rm|lab|hall|lt|cr|lecture theatre)\b|^[A-Za-z]{0,2}-?\d{1,3}[A-Za-z]?$/i';

    /**
     * Stage 1: every sheet into one list of classes.
     *
     * @param  list<Sheet>  $sheets
     */
    public static function parse(array $sheets, string $departmentCode): ParseResult
    {
        $code = strtoupper(trim($departmentCode));
        $result = new ParseResult;

        foreach ($sheets as $sheet) {
            $name = $sheet->name;
            if (in_array(mb_strtolower(trim($name)), self::SKIP_SHEETS, true) || ! self::hasText($sheet->cells)) {
                $result->sheetsSkipped[] = $name;

                continue;
            }

            $before = count($result->rows);
            $flat = self::findFlatHeader($sheet->cells);
            if ($flat !== null) {
                self::parseFlat($name, $sheet->cells, $flat, $code, $result);
            } elseif (! self::parseGrid($name, $sheet->cells, $code, $result)) {
                $result->issues[] = new ImportIssue($name, 0, 'Sheet layout not recognised (no flat header or time grid found)');
                $result->sheetsSkipped[] = $name;

                continue;
            }

            $result->sheetsRead[] = $name;
            array_push($result->issues, ...$sheet->issues);
            if (count($result->rows) === $before) {
                $result->issues[] = new ImportIssue($name, 0, 'Sheet was read but no classes were found', 'warning');
            }
        }

        return $result;
    }

    /**
     * Stage 2: drop rows that make no sense on their own, and exact duplicates.
     * Clashes between rows (a teacher in two rooms at once) are the conflict
     * checker's job, after the import.
     *
     * @param  list<TimetableRow>  $rows
     * @return array{0: list<TimetableRow>, 1: list<ImportIssue>}
     */
    public static function validate(array $rows): array
    {
        $good = [];
        $issues = [];
        $seen = [];

        foreach ($rows as $row) {
            if ($row->endTime <= $row->startTime) {
                $issues[] = new ImportIssue($row->sourceSheet, $row->sourceRow, 'End time is not after start time');

                continue;
            }
            if (trim($row->courseTitle) === '') {
                $issues[] = new ImportIssue($row->sourceSheet, $row->sourceRow, 'Missing course');

                continue;
            }
            if ($tooLong = self::tooLong($row)) {
                // It wouldn't fit the column, and the whole import would fail. Python has no check.
                $issues[] = new ImportIssue($row->sourceSheet, $row->sourceRow, "Too long: $tooLong");

                continue;
            }
            $key = implode("\0", [
                mb_strtolower($row->section), $row->day, $row->startTime, $row->endTime,
                mb_strtolower($row->courseCode !== '' ? $row->courseCode : $row->courseTitle),
            ]);
            if (isset($seen[$key])) {
                $issues[] = new ImportIssue($row->sourceSheet, $row->sourceRow, 'Duplicate row in the file, ignored', 'warning');

                continue;
            }
            $seen[$key] = true;
            $good[] = $row;
        }

        return [$good, $issues];
    }

    /** The column sizes (sections.name, courses.code / title, teachers.name, rooms.name). */
    private const MAX_LENGTHS = ['section' => 50, 'courseCode' => 20, 'courseTitle' => 150, 'teacher' => 100, 'room' => 50];

    private static function tooLong(TimetableRow $row): ?string
    {
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($row->$field) > $max) {
                return strtolower(preg_replace('/(?<!^)([A-Z])/', ' $1', $field))." (max $max characters)";
            }
        }

        return null;
    }

    /** "CS-101 Programming Fundamentals" -> ["CS-101", "Programming Fundamentals"]; no code -> ["", text]. */
    public static function splitCourse(string $text): array
    {
        if (preg_match(self::CODE_RE, $text, $m) && $m[2] !== '') {
            return [trim($m[1]), trim($m[2])];
        }

        return ['', trim($text)];
    }

    /**
     * One grid cell -> [course_code, course_title, teacher, room]; null if empty.
     * Lines are split on newlines or "|":
     *   Course / Teacher / Room,  Course / Teacher,  Course / Room (2nd line looks like a room).
     */
    public static function parseCell(string $text): ?array
    {
        if (in_array(mb_strtolower(trim($text)), self::EMPTY_CELLS, true)) {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\n|]+/', $text)), fn ($l) => $l !== ''));
        if ($lines === []) {
            return null;
        }

        [$code, $title] = self::splitCourse($lines[0]);
        $teacher = $room = '';
        if (count($lines) === 2) {
            preg_match(self::ROOM_HINT, $lines[1]) ? $room = $lines[1] : $teacher = $lines[1];
        } elseif (count($lines) >= 3) {
            [$teacher, $room] = [$lines[1], $lines[count($lines) - 1]];
        }

        return [$code, $title, $teacher, $room];
    }

    // ------------------------------------------------------------------ FLAT layout

    /** @return array{0: int, 1: array<string, int>}|null  header row and field -> column */
    public static function findFlatHeader(array $cells): ?array
    {
        foreach (array_slice($cells, 0, 20) as $r => $row) {
            $cols = [];
            foreach ($row as $c => $cell) {
                $key = mb_strtolower(trim($cell));
                foreach (self::HEADER_ALIASES as $field => $names) {
                    if (in_array($key, $names, true) && ! isset($cols[$field])) {
                        $cols[$field] = $c;
                    }
                }
            }
            $hasTime = isset($cols['time']) || (isset($cols['start_time']) && isset($cols['end_time']));
            $hasCourse = isset($cols['course']) || isset($cols['course_code']);
            if (isset($cols['day']) && $hasTime && $hasCourse) {
                return [$r, $cols];
            }
        }

        return null;
    }

    private static function parseFlat(string $sheet, array $cells, array $header, string $department, ParseResult $result): void
    {
        [$headerRow, $cols] = $header;
        $lastDay = '';

        for ($r = $headerRow + 1; $r < count($cells); $r++) {
            $row = $cells[$r];
            $get = fn (string $f) => isset($cols[$f]) && $cols[$f] < count($row) ? trim($row[$cols[$f]]) : '';
            if (! self::hasText([$row])) {
                continue;
            }

            $dayText = $get('day') !== '' ? $get('day') : $lastDay;   // blank = merged cell, the day above
            $day = DayTime::normalizeDay($dayText);
            $lastDay = $dayText;
            if ($day === null) {
                $result->issues[] = new ImportIssue($sheet, $r + 1, 'Unrecognised day '.DayTime::repr($dayText));

                continue;
            }

            if (isset($cols['time'])) {
                $span = DayTime::parseTimeRange($get('time'));
            } else {
                $s = DayTime::parseTime($get('start_time'));
                $e = DayTime::parseTime($get('end_time'));
                $span = $s !== null && $e !== null ? [$s, $e] : null;
            }
            if ($span === null) {
                $result->issues[] = new ImportIssue($sheet, $r + 1, 'Could not read the time / start / end');

                continue;
            }

            [$code, $title] = [$get('course_code'), $get('course')];
            if ($code === '' && $title !== '') {
                [$code, $title] = self::splitCourse($title);
            }
            if ($title === '' && $code === '') {
                continue;   // an empty slot
            }

            $result->rows[] = new TimetableRow(
                department: $department, section: $get('section') !== '' ? $get('section') : $sheet, day: $day,
                startTime: $span[0], endTime: $span[1], courseTitle: $title !== '' ? $title : $code, courseCode: $code,
                teacher: $get('teacher'), room: $get('room'), sourceSheet: $sheet, sourceRow: $r + 1,
            );
        }
    }

    // ------------------------------------------------------------------ GRID layout

    private static function transpose(array $cells): array
    {
        $width = max(array_map('count', $cells) ?: [0]);
        $out = [];
        for ($c = 0; $c < $width; $c++) {
            $out[] = array_map(fn ($row) => $row[$c] ?? '', $cells);
        }

        return $out;
    }

    /** @return array{0: int, 1: array<int, array{0: string, 1: string}>}|null  header row and column -> time slot */
    private static function findGridHeader(array $cells): ?array
    {
        foreach (array_slice($cells, 0, 15) as $r => $row) {
            $slots = [];
            foreach ($row as $c => $cell) {
                if ($span = DayTime::parseTimeRange($cell)) {
                    $slots[$c] = $span;
                }
            }
            if (count($slots) >= 2) {
                return [$r, $slots];
            }
        }

        return null;
    }

    private static function parseGrid(string $sheet, array $cells, string $department, ParseResult $result): bool
    {
        $transposed = false;
        $header = self::findGridHeader($cells);
        if ($header === null) {
            $cells = self::transpose($cells);
            $transposed = true;
            $header = self::findGridHeader($cells);
            if ($header === null) {
                return false;
            }
        }
        [$headerRow, $slots] = $header;

        $dayCol = null;
        $width = max(array_map('count', $cells) ?: [0]);
        for ($c = 0; $c < min(4, $width) && $dayCol === null; $c++) {
            for ($r = $headerRow + 1; $r < count($cells); $r++) {
                if (DayTime::normalizeDay($cells[$r][$c] ?? '') !== null) {
                    $dayCol = $c;
                    break;
                }
            }
        }
        if ($dayCol === null) {
            return false;
        }

        $lastDay = null;
        for ($r = $headerRow + 1; $r < count($cells); $r++) {
            $row = $cells[$r];
            $day = DayTime::normalizeDay($row[$dayCol] ?? '') ?? $lastDay;
            if ($day === null) {
                continue;
            }
            $lastDay = $day;

            foreach ($slots as $c => [$start, $end]) {
                $parsed = self::parseCell($row[$c] ?? '');
                if ($parsed === null) {
                    continue;
                }
                [$code, $title, $teacher, $room] = $parsed;
                $result->rows[] = new TimetableRow(
                    department: $department, section: $sheet, day: $day, startTime: $start, endTime: $end,
                    courseTitle: $title !== '' ? $title : $code, courseCode: $code, teacher: $teacher, room: $room,
                    // the row in the ORIGINAL sheet (swapped back when transposed)
                    sourceSheet: $sheet, sourceRow: $transposed ? $c + 1 : $r + 1,
                );
            }
        }

        return true;
    }

    private static function hasText(array $cells): bool
    {
        foreach ($cells as $row) {
            foreach ($row as $cell) {
                if ($cell !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
