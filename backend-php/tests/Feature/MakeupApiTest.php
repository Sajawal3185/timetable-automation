<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\MakeupClass;
use App\Models\Notification;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Services\MakeupSolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Port of the solver and booking tests in backend/tests/test_makeup_solver.py. */
class MakeupApiTest extends TestCase
{
    private Department $dept;

    private User $uTeacher;

    private Section $sec;

    private Teacher $t1;

    private Teacher $t2;

    private Course $cLec;

    private Course $cLab;

    private Room $rPref;

    private Room $rLab;

    /** The `seed` fixture: Dr. Ali (t1, has a login), Ms. Sara (t2, imported, no login), five rooms. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create(['code' => 'SE']);
        $this->uTeacher = User::factory()->role('teacher')->create();
        $this->sec = Section::factory()->create(['name' => 'BSSE-5A', 'department_id' => $this->dept->id, 'student_count' => 45]);
        $this->t1 = Teacher::factory()->create(['name' => 'Dr. Ali', 'department_id' => $this->dept->id, 'user_id' => $this->uTeacher->id]);
        $this->t2 = Teacher::factory()->create(['name' => 'Ms. Sara', 'department_id' => $this->dept->id]);
        $this->cLec = Course::factory()->create(['code' => 'SE301', 'department_id' => $this->dept->id, 'is_lab' => false]);
        $this->cLab = Course::factory()->create(['code' => 'SE301L', 'department_id' => $this->dept->id, 'is_lab' => true]);
        Room::factory()->create(['name' => 'R-Small', 'capacity' => 30]);
        $this->rPref = Room::factory()->create(['name' => 'R-Pref', 'capacity' => 50]);
        Room::factory()->create(['name' => 'R-Fallback', 'capacity' => 60]);
        Room::factory()->create(['name' => 'R-Unknown', 'capacity' => null]);
        $this->rLab = Room::factory()->create(['name' => 'Lab-1', 'capacity' => 50, 'room_type' => 'lab']);
    }

    public function test_makeup_solver_room_fallback_and_booking(): void
    {
        // R-Pref is busy Monday 08:00-09:00 for another section, so R-Fallback (60 seats) is chosen then.
        $other = Section::factory()->create(['department_id' => $this->dept->id, 'student_count' => 40]);
        TimetableEntry::factory()->create(['section_id' => $other->id, 'course_id' => $this->cLec->id, 'teacher_id' => $this->t2->id,
            'room_id' => $this->rPref->id, 'day_of_week' => 'Monday', 'start_time' => '08:00:00', 'end_time' => '09:00:00']);
        $this->asUser($this->uTeacher);

        $slots = $this->findSlots(['preferred_room_id' => $this->rPref->id, 'days' => ['Monday']])->assertOk()->json();
        $at = fn (string $t) => collect($slots)->firstWhere('start_time', $t);

        // R-Small (30) and R-Unknown (null) never fit; R-Pref is busy at 08:00.
        $this->assertSame('R-Fallback', $at('08:00:00')['recommended_room']['room_name']);
        $this->assertSame('09:00:00', $at('08:00:00')['end_time']);
        $this->assertSame('R-Pref', $at('09:00:00')['recommended_room']['room_name']);
        $this->assertTrue($at('09:00:00')['recommended_room']['is_preferred']);
        $this->assertSame('R-Fallback', $at('09:00:00')['fallback_rooms'][0]['room_name']);
        $this->assertSame(['room_id', 'room_name', 'capacity', 'room_type', 'building', 'is_preferred'], array_keys($at('09:00:00')['recommended_room']));

        $payload = $this->booking(['room_id' => $this->rPref->id, 'day_of_week' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->postJson('/api/makeup', $payload)->assertCreated();
        $this->postJson('/api/makeup', $payload)->assertStatus(409);
    }

    public function test_slots_respect_campus_hours_and_the_teachers_own_classes(): void
    {
        // Dr. Ali teaches elsewhere Tuesday 10:00-11:30: no slot may overlap it.
        TimetableEntry::factory()->create(['teacher_id' => $this->t1->id, 'day_of_week' => 'Tuesday', 'start_time' => '10:00:00', 'end_time' => '11:30:00']);
        $this->asUser($this->uTeacher);

        $slots = $this->findSlots(['days' => ['Tuesday'], 'duration_minutes' => 90])->assertOk()->json();
        $starts = array_column($slots, 'start_time');
        $this->assertNotContains('09:00:00', $starts);   // 09:00-10:30 overlaps
        $this->assertContains('08:30:00', $starts);      // 08:30-10:00 just touches
        $this->assertContains('15:00:00', $starts);      // ends 16:30, closing time
        $this->assertSame('16:30:00', collect($slots)->last()['end_time']);
    }

    public function test_find_slots_input_errors(): void
    {
        $this->asUser($this->uTeacher);

        $this->findSlots(['days' => ['Funday']])->assertStatus(422)->assertExactJson(['detail' => "Invalid day_of_week: 'Funday'"]);
        $this->findSlots(['duration_minutes' => 10])->assertStatus(422);
        $this->findSlots(['course_id' => 999])->assertStatus(422)->assertExactJson(['detail' => 'Course not found']);
        $this->sec->update(['student_count' => null]);
        $this->assertStringContainsString('student_count is unknown', $this->findSlots()->assertStatus(422)->json('detail'));
    }

    public function test_find_slots_never_offers_a_lab_for_a_lecture_course(): void
    {
        $this->asUser($this->uTeacher);

        $slots = $this->findSlots(['days' => ['Monday']])->assertOk()->json();
        $this->assertNotEmpty($slots);
        $offered = collect($slots)->flatMap(fn ($s) => [$s['recommended_room'], ...$s['fallback_rooms']])->pluck('room_name');
        $this->assertNotContains('Lab-1', $offered);
    }

    public function test_long_gap_is_a_warning_not_a_refusal(): void
    {
        TimetableEntry::factory()->create(['section_id' => $this->sec->id, 'day_of_week' => 'Wednesday', 'start_time' => '08:00:00', 'end_time' => '09:00:00']);
        $this->asUser($this->uTeacher);

        $late = collect($this->findSlots(['days' => ['Wednesday']])->json())->firstWhere('start_time', '14:00:00');
        $this->assertSame(["Section #{$this->sec->id} has a 300-minute gap on Wednesday between 09:00 and 14:00"], $late['soft_warnings']);
    }

    public function test_booking_lecture_course_in_lab_refused(): void
    {
        $this->asUser($this->uTeacher);

        $r = $this->postJson('/api/makeup', $this->booking(['room_id' => $this->rLab->id]))->assertStatus(409);
        $this->assertStringContainsString('lab room', $r->json('detail'));
    }

    public function test_lab_course_can_book_a_lab(): void
    {
        $this->asUser($this->uTeacher);

        $this->postJson('/api/makeup', $this->booking(['course_id' => $this->cLab->id, 'room_id' => $this->rLab->id]))->assertCreated();
    }

    public function test_booking_input_errors(): void
    {
        $this->asUser($this->uTeacher);

        $this->postJson('/api/makeup', $this->booking(['end_time' => '10:00']))->assertStatus(422)
            ->assertExactJson(['detail' => 'end_time must be after start_time']);
        $this->postJson('/api/makeup', $this->booking(['day_of_week' => 'tue']))->assertStatus(422);
        $this->postJson('/api/makeup', $this->booking(['start_time' => '11am']))->assertStatus(422);
        $this->postJson('/api/makeup', $this->booking(['room_id' => 999]))->assertNotFound()
            ->assertExactJson(['detail' => 'Section, teacher, course, or room not found']);
        $this->postJson('/api/makeup', $this->booking(['room_id' => Room::where('name', 'R-Small')->value('id')]))->assertStatus(409)
            ->assertExactJson(['detail' => 'Room capacity (30) is smaller than section size (45)']);
    }

    public function test_teacher_cannot_search_or_book_for_another_teacher(): void
    {
        $this->asUser($this->uTeacher);   // Dr. Ali, acting for Ms. Sara

        $this->findSlots(['teacher_id' => $this->t2->id])->assertForbidden()
            ->assertExactJson(['detail' => 'You can only book makeup classes for yourself']);
        $this->postJson('/api/makeup', $this->booking(['teacher_id' => $this->t2->id]))->assertForbidden();
        $this->assertSame(0, MakeupClass::count());
    }

    public function test_students_cannot_search_or_book(): void
    {
        $this->asRole('student');

        $this->findSlots()->assertForbidden();
        $this->postJson('/api/makeup', $this->booking())->assertForbidden();
    }

    public function test_hod_books_only_within_own_department_admin_anywhere(): void
    {
        $other = Department::factory()->create(['code' => 'CS']);

        $this->asRole('hod', ['department_id' => $other->id]);
        $this->postJson('/api/makeup', $this->booking(['teacher_id' => $this->t2->id]))->assertForbidden();
        $this->asRole('hod', ['department_id' => $this->dept->id]);
        $this->postJson('/api/makeup', $this->booking(['teacher_id' => $this->t2->id]))->assertCreated();
        $this->asRole('admin');
        $this->postJson('/api/makeup', $this->booking(['day_of_week' => 'Wednesday']))->assertCreated();
    }

    public function test_second_booking_of_same_slot_refused(): void
    {
        $this->asUser($this->uTeacher);

        $this->postJson('/api/makeup', $this->booking())->assertCreated();
        $this->postJson('/api/makeup', $this->booking())->assertStatus(409)
            ->assertExactJson(['detail' => "Teacher #{$this->t1->id} is already booked 11:00-12:00"]);
        $this->assertSame(1, MakeupClass::count());
    }

    public function test_database_refuses_double_booking_when_clash_check_is_bypassed(): void
    {
        // Two requests racing past the clash check: the unique key stops the second, 409 not 500.
        $this->instance(MakeupSolver::class, new class extends MakeupSolver
        {
            public function activeSlots(string $term): array
            {
                return [];
            }
        });
        $other = Section::factory()->create(['department_id' => $this->dept->id, 'student_count' => 40]);
        $this->asUser($this->uTeacher);

        $this->postJson('/api/makeup', $this->booking())->assertCreated();
        // same room, day and start time, different section: only the database can catch it now
        $r = $this->postJson('/api/makeup', $this->booking(['section_id' => $other->id]))->assertStatus(409);
        $this->assertStringContainsString('just booked', $r->json('detail'));
        $this->assertSame(1, MakeupClass::count());
    }

    public function test_cancelled_makeup_does_not_block_the_slot_in_the_database(): void
    {
        $row = fn (string $status) => MakeupClass::create([
            'course_id' => $this->cLec->id, 'section_id' => $this->sec->id, 'teacher_id' => $this->t1->id, 'room_id' => $this->rPref->id,
            'day_of_week' => 'Thursday', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'status' => $status,
        ]);
        $row('cancelled');
        $row('cancelled');
        $row('scheduled');   // cancelled rows never collide; one scheduled row is fine

        $this->expectException(UniqueConstraintViolationException::class);
        $row('scheduled');
    }

    public function test_booked_class_fields_and_list(): void
    {
        $this->asUser($this->uTeacher);
        $id = $this->postJson('/api/makeup', $this->booking(['class_date' => '2026-12-01', 'reason' => 'Missed Monday']))->json('id');
        MakeupClass::create(['course_id' => $this->cLec->id, 'section_id' => $this->sec->id, 'teacher_id' => $this->t1->id,
            'room_id' => $this->rPref->id, 'day_of_week' => 'Friday', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'status' => 'cancelled']);

        $this->asRole('student');
        $this->getJson('/api/makeup')->assertOk()->assertExactJson([[
            'id' => $id, 'course_id' => $this->cLec->id, 'section_id' => $this->sec->id, 'teacher_id' => $this->t1->id,
            'room_id' => $this->rPref->id, 'class_date' => '2026-12-01', 'day_of_week' => 'Tuesday',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'reason' => 'Missed Monday', 'status' => 'scheduled',
        ]]);   // the cancelled one isn't listed
        $this->assertCount(1, $this->getJson("/api/makeup?teacher_id={$this->t1->id}")->json());
        $this->assertSame([], $this->getJson("/api/makeup?section_id=999")->json());
        $this->assertSame('Missed Monday', MakeupClass::find($id)->reason);
    }

    // ------------------------------------------------------------ notifications

    public function test_booking_notifies_the_teacher_and_the_sections_students(): void
    {
        $student = User::factory()->role('student')->create(['section_id' => $this->sec->id]);
        User::factory()->role('student')->create();   // no section: not told
        $this->asUser($this->uTeacher);

        $id = $this->postJson('/api/makeup', $this->booking(['class_date' => '2026-12-01']))->assertCreated()->json('id');

        $rows = Notification::all();
        $this->assertEqualsCanonicalizing([$this->uTeacher->id, $student->id], $rows->pluck('user_id')->all());
        $this->assertSame(['makeup_added'], $rows->pluck('type')->unique()->values()->all());
        $this->assertSame([$id], $rows->pluck('related_id')->unique()->values()->all());
        $this->assertSame('Makeup class added: SE301 on 01 Dec 2026, Tuesday 11:00-12:00 in R-Pref.', $rows[0]->message);

        // a refused booking (same slot again) leaves no extra notification behind
        $this->postJson('/api/makeup', $this->booking())->assertStatus(409);
        $this->assertSame(2, Notification::count());
    }

    public function test_booking_for_a_teacher_without_a_login_still_works(): void
    {
        $this->asRole('admin');   // Ms. Sara is imported: no login, nobody to notify

        $this->postJson('/api/makeup', $this->booking(['teacher_id' => $this->t2->id]))->assertCreated();
        $this->assertSame(0, Notification::count());
    }

    // ------------------------------------------------------------ helpers

    private function findSlots(array $changes = []): TestResponse
    {
        return $this->postJson('/api/makeup/find-slots', [
            'section_id' => $this->sec->id, 'teacher_id' => $this->t1->id, 'course_id' => $this->cLec->id, 'duration_minutes' => 60, ...$changes,
        ]);
    }

    private function booking(array $changes = []): array
    {
        return [
            'course_id' => $this->cLec->id, 'section_id' => $this->sec->id, 'teacher_id' => $this->t1->id,
            'room_id' => $this->rPref->id, 'day_of_week' => 'Tuesday', 'start_time' => '11:00', 'end_time' => '12:00', ...$changes,
        ];
    }
}
