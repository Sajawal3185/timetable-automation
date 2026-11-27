<?php

namespace App\Services;

use App\Models\Course;
use App\Models\MakeupClass;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Support\Fmt;
use InvalidArgumentException;

/**
 * Finds free makeup slots (backend/app/services/makeup_solver.py): every start
 * time where the teacher and the section are both free, with the rooms that
 * fit and are free then. The preferred room comes first if free, then the
 * smallest room that fits. A slot that leaves the section a long gap that day
 * is still offered, with a warning.
 *
 * Not final, and used through the container (app(MakeupSolver::class)), so a
 * test can replace activeSlots() to act out two bookings racing.
 */
class MakeupSolver
{
    public const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    /** NUML's usual start times, 08:00 to 15:00. */
    public const CANDIDATE_STARTS = [
        '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '10:45', '11:00',
        '11:30', '11:40', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00',
    ];

    public const CAMPUS_CLOSE = '16:30:00';

    /**
     * Everything that takes a teacher, room or section: the term's timetable
     * entries and every scheduled makeup class (makeups have no term).
     *
     * @return list<array>
     */
    public function activeSlots(string $term): array
    {
        $slots = [];
        foreach (TimetableEntry::where('term', $term)->orderBy('id')->get() as $e) {
            $slots[] = [
                'day' => $e->day_of_week, 'start' => Fmt::time($e->start_time), 'end' => Fmt::time($e->end_time),
                'section_id' => $e->section_id, 'course_id' => $e->course_id, 'teacher_id' => $e->teacher_id,
                'room_id' => $e->room_id, 'elective_group' => $e->elective_group, 'group_label' => $e->group_label ?? '',
                'entry_id' => $e->id, 'entry_type' => 'timetable',
            ];
        }
        foreach (MakeupClass::where('status', 'scheduled')->orderBy('id')->get() as $m) {
            $slots[] = [
                'day' => $m->day_of_week, 'start' => Fmt::time($m->start_time), 'end' => Fmt::time($m->end_time),
                'section_id' => $m->section_id, 'course_id' => $m->course_id, 'teacher_id' => $m->teacher_id,
                'room_id' => $m->room_id, 'entry_id' => $m->id, 'entry_type' => 'makeup',
            ];
        }

        return $slots;
    }

    /**
     * MakeupSlotSuggestionOut rows (schemas/makeup.py).
     *
     * @param  list<string>  $days
     * @return list<array>
     *
     * @throws InvalidArgumentException a missing section/teacher/course or an unknown section size (the API answers 422)
     */
    public function find(int $sectionId, int $teacherId, int $courseId, int $durationMinutes,
        ?int $preferredRoomId = null, array $days = self::WEEKDAYS, string $term = 'Fall 2026'): array
    {
        $section = Section::find($sectionId) ?? throw new InvalidArgumentException('Section not found');
        Teacher::find($teacherId) ?? throw new InvalidArgumentException('Teacher not found');
        $course = Course::find($courseId) ?? throw new InvalidArgumentException('Course not found');
        if ($section->student_count === null) {
            throw new InvalidArgumentException('Section student_count is unknown (None); set student_count before running the solver');
        }

        // Rooms that fit: known capacity, enough seats, lab for a lab course and only then.
        $rooms = Room::orderBy('capacity')->orderBy('name')->get()->filter(fn (Room $r) => SchedulerRules::roomSuitability(
            $section->student_count, $r->capacity, $course->is_lab, $r->room_type) === [])->values();
        if ($rooms->isEmpty()) {
            return [];
        }

        $existing = $this->activeSlots($term);
        $suggestions = [];
        foreach ($days as $day) {
            $sectionDay = array_values(array_filter($existing, fn ($s) => $s['day'] === $day && $s['section_id'] === $sectionId));
            foreach (self::CANDIDATE_STARTS as $start) {
                $endMinutes = SchedulerRules::minutes($start) + $durationMinutes;
                $end = sprintf('%02d:%02d:00', intdiv($endMinutes, 60), $endMinutes % 60);
                if ($endMinutes >= 24 * 60 || $end > self::CAMPUS_CLOSE) {
                    continue;
                }

                // 1. Teacher and section free?
                $probe = ['day' => $day, 'start' => "$start:00", 'end' => $end,
                    'section_id' => $sectionId, 'course_id' => $courseId, 'teacher_id' => $teacherId];
                if (SchedulerRules::hardClashes($probe, $existing) !== []) {
                    continue;
                }

                // 2. Which of the fitting rooms are free then?
                $free = [];
                foreach ($rooms as $room) {
                    if (SchedulerRules::hardClashes([...$probe, 'room_id' => $room->id], $existing) === []) {
                        $free[] = [
                            'room_id' => $room->id, 'room_name' => $room->name, 'capacity' => $room->capacity ?? 0,
                            'room_type' => $room->room_type, 'building' => $room->building,
                            'is_preferred' => $room->id === $preferredRoomId,
                        ];
                    }
                }
                if ($free === []) {
                    continue;
                }
                usort($free, fn ($a, $b) => [! $a['is_preferred'], $a['capacity'], $a['room_name']]
                    <=> [! $b['is_preferred'], $b['capacity'], $b['room_name']]);

                // 3. Soft rule: the section's gaps that day if this slot is taken.
                $warnings = SchedulerRules::softSectionGaps([...$sectionDay, $probe]);
                $suggestions[] = [
                    'day_of_week' => $day, 'start_time' => "$start:00", 'end_time' => $end,
                    'recommended_room' => $free[0], 'fallback_rooms' => array_slice($free, 1),
                    'soft_warnings' => array_column($warnings, 'message'),
                ];
            }
        }

        return $suggestions;
    }
}
