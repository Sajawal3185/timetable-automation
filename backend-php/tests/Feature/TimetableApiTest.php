<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use Tests\TestCase;

/**
 * GET /api/timetable, from test_list_timetable_filters in backend/tests/test_timetable_api.py.
 * Python fills the table through the importer; the importer isn't ported, so
 * the same two Monday rows are made with factories here.
 */
class TimetableApiTest extends TestCase
{
    private Department $dept;

    private Section $section;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create(['code' => 'SE']);
        $this->section = Section::factory()->create(['department_id' => $this->dept->id]);
        $this->teacher = Teacher::factory()->create();
        foreach ([['08:30:00', '10:00:00'], ['10:00:00', '11:30:00']] as [$start, $end]) {
            TimetableEntry::factory()->create([
                'section_id' => $this->section->id, 'teacher_id' => $this->teacher->id,
                'day_of_week' => 'Monday', 'start_time' => $start, 'end_time' => $end,
            ]);
        }
    }

    public function test_list_timetable_filters(): void
    {
        $this->asRole('student');

        $this->assertCount(2, $this->getJson('/api/timetable')->assertOk()->json());
        $this->assertCount(2, $this->getJson('/api/timetable?day=mon')->json());
        $this->assertSame([], $this->getJson('/api/timetable?day=Tuesday')->json());
        $this->assertCount(2, $this->getJson("/api/timetable?department_id={$this->dept->id}")->json());
        $this->assertSame([], $this->getJson('/api/timetable?department_id=999')->json());
        $this->getJson('/api/timetable?day=xyz')->assertStatus(422)->assertExactJson(['detail' => "Unrecognised day: 'xyz'"]);
    }

    public function test_teacher_and_section_filters_for_the_personal_timetable(): void
    {
        TimetableEntry::factory()->create(['day_of_week' => 'Friday']);   // someone else's class
        $this->asRole('teacher');

        $this->assertCount(3, $this->getJson('/api/timetable')->json());
        $this->assertCount(2, $this->getJson("/api/timetable?teacher_id={$this->teacher->id}")->json());
        $this->assertCount(2, $this->getJson("/api/timetable?section_id={$this->section->id}")->json());
        $this->assertCount(3, $this->getJson('/api/timetable?teacher_id=0')->json());   // 0 = no filter, as in Python
    }

    public function test_entry_fields_and_order(): void
    {
        $this->asRole('student');

        $first = TimetableEntry::orderBy('start_time')->first();
        $rows = $this->getJson('/api/timetable')->json();
        $this->assertSame(['08:30:00', '10:00:00'], array_column($rows, 'start_time'));
        $this->assertSame([
            'id' => $first->id, 'section_id' => $this->section->id, 'course_id' => $first->course_id,
            'teacher_id' => $this->teacher->id, 'room_id' => $first->room_id,
            'day_of_week' => 'Monday', 'start_time' => '08:30:00', 'end_time' => '10:00:00',
        ], $rows[0]);
    }

    public function test_timetable_needs_a_login_and_checks_limits(): void
    {
        $this->getJson('/api/timetable')->assertUnauthorized();

        $this->asRole('student');
        $this->getJson('/api/timetable?limit=2001')->assertStatus(422);
        $this->getJson('/api/timetable?skip=-1')->assertStatus(422);
        $this->assertCount(1, $this->getJson('/api/timetable?limit=1&skip=1')->json());
    }
}
