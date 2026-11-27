<?php

namespace App\Services\TimetableImport;

use App\Models\Course;
use App\Models\Department;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stage 3 of timetable_import.py (load_rows): writes checked rows into the
 * core tables. It splits each row into its section, course, teacher and room,
 * finds each one by name (ignoring case) or creates it, then adds the
 * timetable entry. Run it inside a transaction; the controller does.
 *
 * Importing the same file again is safe: an identical entry is skipped.
 */
final class TimetableLoader
{
    private const EMPTY_NAMES = ['', 'tba', 'tbd', 'n/a', '-'];

    /** @param list<TimetableRow> $rows */
    public static function load(array $rows, string $departmentCode): LoadSummary
    {
        $summary = new LoadSummary;
        $code = strtoupper(trim($departmentCode));

        $dept = self::firstByName(Department::query(), 'code', $code);
        if ($dept === null) {
            $dept = Department::create(['code' => $code, 'name' => $code]);
            $summary->departmentsCreated++;
        }

        $sections = $courses = $teachers = $rooms = [];

        foreach ($rows as $row) {
            $sectionKey = mb_strtolower($row->section);
            if (! isset($sections[$sectionKey])) {
                $section = self::firstByName(Section::where('department_id', $dept->id), 'name', $row->section);
                if ($section === null) {
                    $section = Section::create(['name' => $row->section, 'department_id' => $dept->id]);
                    $summary->sectionsCreated++;
                }
                $sections[$sectionKey] = $section;
            }

            $courseKey = mb_strtolower($row->courseCode !== '' ? $row->courseCode : $row->courseTitle);
            if (! isset($courses[$courseKey])) {
                // A code is looked up in every department (like Python); a bare title only in this one.
                $course = $row->courseCode !== ''
                    ? self::firstByName(Course::query(), 'code', $row->courseCode)
                    : self::firstByName(Course::where('department_id', $dept->id), 'title', $row->courseTitle);
                if ($course === null) {
                    $newCode = $row->courseCode !== '' ? $row->courseCode : self::madeUpCode($row->courseTitle, $dept->id);
                    $course = Course::create(['code' => $newCode, 'title' => $row->courseTitle, 'department_id' => $dept->id]);
                    $summary->coursesCreated++;
                }
                $courses[$courseKey] = $course;
            }

            $teacherId = null;
            if (! in_array(mb_strtolower(trim($row->teacher)), self::EMPTY_NAMES, true)) {
                $teacherKey = mb_strtolower($row->teacher);
                if (! isset($teachers[$teacherKey])) {
                    $teacher = self::firstByName(Teacher::query(), 'name', $row->teacher);
                    if ($teacher === null) {
                        $teacher = Teacher::create(['name' => $row->teacher, 'department_id' => $dept->id]);
                        $summary->teachersCreated++;
                    }
                    $teachers[$teacherKey] = $teacher;
                }
                $teacherId = $teachers[$teacherKey]->id;
            }

            $roomId = null;
            if (! in_array(mb_strtolower(trim($row->room)), self::EMPTY_NAMES, true)) {
                $roomKey = mb_strtolower($row->room);
                if (! isset($rooms[$roomKey])) {
                    $room = self::firstByName(Room::query(), 'name', $row->room);
                    if ($room === null) {
                        // The file has no capacity: store NULL (unknown), never a made-up number.
                        // The makeup solver skips such rooms until an admin sets it (PUT /api/rooms/{id}).
                        $room = Room::create(['name' => $row->room]);
                        $summary->roomsCreated[] = $row->room;
                    }
                    $rooms[$roomKey] = $room;
                }
                $roomId = $rooms[$roomKey]->id;
            }

            $entry = [
                'section_id' => $sections[$sectionKey]->id, 'course_id' => $courses[$courseKey]->id,
                'day_of_week' => $row->day, 'start_time' => $row->startTime, 'end_time' => $row->endTime,
            ];
            if (TimetableEntry::where($entry)->exists()) {
                $summary->entriesSkippedExisting++;

                continue;
            }
            TimetableEntry::create([...$entry, 'teacher_id' => $teacherId, 'room_id' => $roomId]);
            $summary->entriesCreated++;
        }

        return $summary;
    }

    /**
     * A code for a course that has only a title: its letters and digits, at most
     * 20. Different titles can give the same code ("Digital Logic Design" and
     * "Digital Logic & Design", or two long titles cut at 20), and codes are
     * unique per department, so a taken code gets -2, -3, ... (Python crashes here.)
     */
    private static function madeUpCode(string $title, int $departmentId): string
    {
        $base = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $title)), 0, 20) ?: 'COURSE';
        $code = $base;
        for ($n = 2; Course::where('department_id', $departmentId)->where('code', $code)->exists(); $n++) {
            $code = substr($base, 0, 20 - strlen("-$n"))."-$n";
        }

        return $code;
    }

    /** The first row whose $column equals $value, ignoring case (Python's _ci_first). */
    private static function firstByName(Builder $query, string $column, string $value): mixed
    {
        return $query->whereRaw("LOWER($column) = ?", [mb_strtolower($value)])->orderBy('id')->first();
    }
}
