<?php

namespace App\Services\TimetableImport;

/** One class read from a file (TimetableRow in timetable_import.py). Times are "HH:MM:SS". */
final class TimetableRow
{
    public function __construct(
        public string $department,
        public string $section,
        public string $day,
        public string $startTime,
        public string $endTime,
        public string $courseTitle,
        public string $courseCode = '',
        public string $teacher = '',
        public string $room = '',
        public string $sourceSheet = '',
        public int $sourceRow = 0,
    ) {}
}
