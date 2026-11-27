<?php

namespace Tests\Unit;

use App\Services\SchedulerRules;
use PHPUnit\Framework\TestCase;

/** The pure rule tests of backend/tests/test_makeup_solver.py. */
class SchedulerRulesTest extends TestCase
{
    public function test_elective_group_exemption_and_hard_clashes(): void
    {
        $existing = [$this->slot('08:30', '10:00', ['section_id' => 1, 'course_id' => 10, 'teacher_id' => 1, 'room_id' => 1, 'elective_group' => 'ELEC-1'])];

        // a parallel elective of the same section, other teacher and room, same group: no clash
        $parallel = $this->slot('08:30', '10:00', ['section_id' => 1, 'course_id' => 11, 'teacher_id' => 2, 'room_id' => 2, 'elective_group' => 'ELEC-1']);
        $this->assertSame([], SchedulerRules::hardClashes($parallel, $existing));

        // without the elective group: a section clash
        $regular = $this->slot('09:00', '10:30', ['section_id' => 1, 'course_id' => 12, 'teacher_id' => 3, 'room_id' => 3]);
        $this->assertSame(['section_clash'], array_column(SchedulerRules::hardClashes($regular, $existing), 'kind'));
    }

    public function test_lab_groups_of_one_section_may_run_in_parallel(): void
    {
        $existing = [$this->slot('09:00', '10:00', ['section_id' => 1, 'teacher_id' => 1, 'room_id' => 1, 'group_label' => 'Group A'])];

        $this->assertSame([], SchedulerRules::hardClashes($this->slot('09:00', '10:00', ['section_id' => 1, 'teacher_id' => 2, 'room_id' => 2, 'group_label' => 'Group B']), $existing));
        $this->assertSame(['section_clash'], array_column(SchedulerRules::hardClashes($this->slot('09:00', '10:00', ['section_id' => 1, 'teacher_id' => 2, 'room_id' => 2]), $existing), 'kind'));
    }

    public function test_cross_table_id_collision_not_skipped(): void
    {
        // TimetableEntry 1 and MakeupClass 1 share an id but are different rows.
        $existing = [$this->slot('09:00', '10:00', ['section_id' => 2, 'teacher_id' => 1, 'room_id' => 5, 'entry_id' => 1, 'entry_type' => 'makeup'])];
        $candidate = $this->slot('09:00', '10:00', ['section_id' => 3, 'teacher_id' => 1, 'room_id' => 6, 'entry_id' => 1, 'entry_type' => 'timetable']);

        $clashes = SchedulerRules::hardClashes($candidate, $existing);
        $this->assertSame(['teacher_clash'], array_column($clashes, 'kind'));
        $this->assertSame('Teacher #1 is already booked 09:00-10:00', $clashes[0]['message']);
        // the same row is not a clash with itself
        $this->assertSame([], SchedulerRules::hardClashes([...$candidate, 'entry_type' => 'makeup'], $existing));
    }

    public function test_unknown_capacity_and_lab_type_rejected(): void
    {
        $this->assertSame('unknown_capacity', SchedulerRules::roomSuitability(40, null)[0]['kind']);
        $this->assertSame('room_type_mismatch', SchedulerRules::roomSuitability(40, 50, true, 'lecture')[0]['kind']);
        $this->assertSame('room_capacity', SchedulerRules::roomSuitability(40, 30)[0]['kind']);
    }

    public function test_lecture_course_refused_in_lab_room(): void
    {
        $this->assertSame(['room_type_mismatch'], array_column(SchedulerRules::roomSuitability(40, 50, false, 'lab'), 'kind'));
        $this->assertSame([], SchedulerRules::roomSuitability(40, 50, true, 'lab'));
        $this->assertSame([], SchedulerRules::roomSuitability(40, 50, false, 'lecture'));
    }

    public function test_soft_rule_long_student_gap(): void
    {
        $warnings = SchedulerRules::softSectionGaps([
            $this->slot('08:00', '09:00', ['section_id' => 1]),
            $this->slot('12:00', '13:00', ['section_id' => 1]),
        ]);

        $this->assertSame(['long_student_gap'], array_column($warnings, 'kind'));
        $this->assertSame('Section #1 has a 180-minute gap on Monday between 09:00 and 12:00', $warnings[0]['message']);
        $this->assertSame([], SchedulerRules::softSectionGaps([$this->slot('08:00', '09:00', ['section_id' => 1]), $this->slot('11:00', '12:00', ['section_id' => 1])]));
    }

    private function slot(string $start, string $end, array $fields): array
    {
        return ['day' => 'Monday', 'start' => "$start:00", 'end' => "$end:00", 'course_id' => 1, ...$fields];
    }
}
