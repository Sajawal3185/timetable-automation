<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Department;
use App\Models\MakeupClass;
use App\Models\Notification;
use App\Models\Room;
use App\Models\Section;
use App\Models\SwapRequest;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The demo data (backend/scripts/seed.py): the same small university as
 * frontend/mock/db/, so pages look the same in mock mode and on the backend.
 *
 *   2 departments, 3 sections, 5 rooms, 6 courses, 4 teachers, 14 timetable entries
 *   7 accounts: admin, HOD (SE), 2 teachers, 2 activated students and 1 student
 *               who has NOT activated yet (for the activation demo)
 *
 * A plain run is safe to repeat: missing rows are added, existing ones are left
 * alone, and the timetable is only loaded while the seeded sections have none.
 *
 * reset: back to the starting state. It DELETES EVERY swap request, makeup class
 * and notification, reloads the seeded sections' timetable (approved swaps moved
 * it), and sets every demo password back (and un-activates the last student).
 * Demo databases only.
 *
 * No password is written here: every activated demo account gets SEED_PASSWORD.
 * Run it with `php artisan demo:seed [--reset]` or `php artisan db:seed`.
 */
final class DemoSeed
{
    public const TERM = 'Fall 2026';

    public const MIN_PASSWORD = 8;

    /** code => name */
    public const DEPARTMENTS = ['SE' => 'Software Engineering', 'CS' => 'Computer Science'];

    /** [name, department code, semester, student_count] */
    public const SECTIONS = [['BSSE-5A', 'SE', 5, 45], ['BSSE-5B', 'SE', 5, 38], ['BSCS-3A', 'CS', 3, 50]];

    /** [name, capacity, room_type, building] */
    public const ROOMS = [
        ['R-101', 50, 'lecture', 'Block A'],
        ['R-102', 40, 'lecture', 'Block A'],
        ['R-201', 60, 'lecture', 'Block B'],
        ['Lab-1', 50, 'lab', 'Block B'],
        ['Lab-2', 30, 'lab', 'Block B'],
    ];

    /** [code, title, credit_hours, is_lab, department code] */
    public const COURSES = [
        ['SE-311', 'Software Design and Architecture', 3, false, 'SE'],
        ['SE-312', 'Software Quality Engineering', 3, false, 'SE'],
        ['SE-313', 'Operating Systems Lab', 1, true, 'SE'],
        ['SE-314', 'Database Systems', 3, false, 'SE'],
        ['SE-315', 'Software Requirements Engineering', 3, false, 'SE'],
        ['CS-201', 'Data Structures and Algorithms', 3, false, 'CS'],
    ];

    /** [identifier (lowercase, as login stores it), name, role, department code, section, activated] */
    public const USERS = [
        ['adm-100001', 'Admin Office', 'admin', null, null, true],
        ['hod-100301', 'Ms. Sara Khan', 'hod', 'SE', null, true],
        ['tr-100201', 'Dr. Ahmed Raza', 'teacher', 'SE', null, true],
        ['tr-200401', 'Dr. Usman Ali', 'teacher', 'CS', null, true],
        ['se-9244330', 'Ali Hassan', 'student', 'SE', 'BSSE-5A', true],
        ['cs-9255101', 'Hina Malik', 'student', 'CS', 'BSCS-3A', true],
        // On the roster but no password yet: POST /api/auth/activate claims it.
        ['se-9244331', 'Fatima Noor', 'student', 'SE', 'BSSE-5B', false],
    ];

    /**
     * [name, department code, identifier of the linked login or null]. Mr. Bilal Ahmed has
     * no login on purpose (imported teachers look like that); the SE HOD also teaches.
     */
    public const TEACHERS = [
        ['Dr. Ahmed Raza', 'SE', 'tr-100201'],
        ['Ms. Sara Khan', 'SE', 'hod-100301'],
        ['Mr. Bilal Ahmed', 'SE', null],
        ['Dr. Usman Ali', 'CS', 'tr-200401'],
    ];

    /**
     * [section, course code, teacher, room, day, start, end]. The demo relies on two things:
     *   clash: swapping Mon 08:30 SE-311 (5A) with Mon 10:00 SE-312 (5A) is refused,
     *          because Ms. Sara Khan already teaches 5B on Monday 08:30;
     *   free:  swapping Wed 10:00 SE-311 (5A) with Wed 08:30 SE-315 (5B) is clean.
     */
    public const TIMETABLE = [
        ['BSSE-5A', 'SE-311', 'Dr. Ahmed Raza', 'R-101', 'Monday', '08:30', '10:00'],
        ['BSSE-5A', 'SE-312', 'Ms. Sara Khan', 'R-101', 'Monday', '10:00', '11:30'],
        ['BSSE-5A', 'SE-314', 'Mr. Bilal Ahmed', 'R-201', 'Tuesday', '08:30', '10:00'],
        ['BSSE-5A', 'SE-313', 'Dr. Ahmed Raza', 'Lab-1', 'Tuesday', '11:40', '13:10'],
        ['BSSE-5A', 'SE-311', 'Dr. Ahmed Raza', 'R-101', 'Wednesday', '10:00', '11:30'],
        ['BSSE-5A', 'SE-315', 'Ms. Sara Khan', 'R-201', 'Thursday', '08:30', '10:00'],
        ['BSSE-5A', 'SE-314', 'Mr. Bilal Ahmed', 'R-201', 'Friday', '10:00', '11:30'],
        ['BSSE-5B', 'SE-312', 'Ms. Sara Khan', 'R-102', 'Monday', '08:30', '10:00'],
        ['BSSE-5B', 'SE-311', 'Dr. Ahmed Raza', 'R-102', 'Monday', '11:40', '13:10'],
        ['BSSE-5B', 'SE-315', 'Ms. Sara Khan', 'R-102', 'Wednesday', '08:30', '10:00'],
        ['BSSE-5B', 'SE-314', 'Mr. Bilal Ahmed', 'R-102', 'Thursday', '10:00', '11:30'],
        ['BSSE-5B', 'SE-311', 'Dr. Ahmed Raza', 'R-102', 'Friday', '08:30', '10:00'],
        ['BSCS-3A', 'CS-201', 'Dr. Usman Ali', 'R-201', 'Monday', '10:00', '11:30'],
        ['BSCS-3A', 'CS-201', 'Dr. Usman Ali', 'R-201', 'Wednesday', '11:40', '13:10'],
    ];

    /**
     * Loads the demo data in one transaction.
     *
     * @return array{added: int, timetable_entries: int, swaps_deleted: int, makeups_deleted: int, notifications_deleted: int}
     *
     * @throws InvalidArgumentException a password shorter than MIN_PASSWORD
     */
    public static function run(string $password, bool $reset = false): array
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('The seed password needs at least '.self::MIN_PASSWORD.' characters');
        }

        return DB::transaction(fn () => self::load($password, $reset));
    }

    private static function load(string $password, bool $reset): array
    {
        $done = ['added' => 0, 'timetable_entries' => 0, 'swaps_deleted' => 0, 'makeups_deleted' => 0, 'notifications_deleted' => 0];
        $add = function (Model $row) use (&$done): Model {
            $row->save();
            $done['added']++;

            return $row;
        };

        // ---- reference data: add what is missing, never change what is there
        $depts = [];
        foreach (self::DEPARTMENTS as $code => $name) {
            $depts[$code] = Department::where('code', $code)->first() ?? $add(new Department(['code' => $code, 'name' => $name]));
        }

        $sections = [];
        foreach (self::SECTIONS as [$name, $dept, $semester, $count]) {
            $sections[$name] = Section::where('name', $name)->where('department_id', $depts[$dept]->id)->first()
                ?? $add(new Section(['name' => $name, 'department_id' => $depts[$dept]->id, 'semester' => $semester, 'student_count' => $count]));
        }

        $rooms = [];
        foreach (self::ROOMS as [$name, $capacity, $type, $building]) {
            $rooms[$name] = Room::where('name', $name)->first()
                ?? $add(new Room(['name' => $name, 'capacity' => $capacity, 'room_type' => $type, 'building' => $building]));
        }

        $courses = [];
        foreach (self::COURSES as [$code, $title, $credits, $isLab, $dept]) {
            $courses[$code] = Course::where('code', $code)->where('department_id', $depts[$dept]->id)->first()
                ?? $add(new Course(['code' => $code, 'title' => $title, 'credit_hours' => $credits, 'is_lab' => $isLab, 'department_id' => $depts[$dept]->id]));
        }

        // ---- accounts. One hash for all: bcrypt is slow on purpose, and the
        //      demo accounts share the password anyway.
        $hash = Security::hashPassword($password);
        $users = [];
        foreach (self::USERS as [$identifier, $name, $role, $dept, $section, $activated]) {
            $user = User::where('identifier', $identifier)->first();
            if ($user === null) {
                $user = new User;
                $user->forceFill(['identifier' => $identifier, 'name' => $name, 'password_hash' => $activated ? $hash : null]);
                $done['added']++;
            } elseif ($reset) {
                $user->password_hash = $activated ? $hash : null;
            }
            // Role, department and section are what the demo depends on (a HOD with no
            // department can approve nothing), so they are always put right.
            $user->forceFill([
                'role' => $role, 'is_active' => true,
                'department_id' => $dept ? $depts[$dept]->id : null,
                'section_id' => $section ? $sections[$section]->id : null,
            ])->save();
            $users[$identifier] = $user;
        }

        $teachers = [];
        foreach (self::TEACHERS as [$name, $dept, $identifier]) {
            // Same lookup as the timetable importer (name, ignoring case), so a later
            // import of a real sheet reuses these rows instead of duplicating them.
            $teacher = Teacher::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first() ?? $add(new Teacher(['name' => $name]));
            $teacher->update(['department_id' => $depts[$dept]->id, 'user_id' => $identifier ? $users[$identifier]->id : null]);
            $teachers[$name] = $teacher;
        }

        // ---- back to the starting state
        $seeded = fn () => TimetableEntry::whereIn('section_id', array_map(fn ($s) => $s->id, $sections))->where('term', self::TERM);
        if ($reset) {
            // Swap requests point at timetable entries, so they go first.
            $done['swaps_deleted'] = SwapRequest::query()->delete();
            $done['makeups_deleted'] = MakeupClass::query()->delete();
            $done['notifications_deleted'] = Notification::query()->delete();
            $seeded()->delete();
        }

        // ---- timetable: only into empty sections, so a plain re-run adds nothing
        if (! $seeded()->exists()) {
            foreach (self::TIMETABLE as [$section, $course, $teacher, $room, $day, $start, $end]) {
                TimetableEntry::create([
                    'term' => self::TERM, 'section_id' => $sections[$section]->id, 'course_id' => $courses[$course]->id,
                    'teacher_id' => $teachers[$teacher]->id, 'room_id' => $rooms[$room]->id,
                    'day_of_week' => $day, 'start_time' => "$start:00", 'end_time' => "$end:00",
                ]);
            }
            $done['timetable_entries'] = count(self::TIMETABLE);
        }

        return $done;
    }
}
