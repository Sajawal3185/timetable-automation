<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Services\ConflictChecker;
use App\Services\SwapService;
use Tests\TestCase;

/** Port of backend/tests/test_conflict_checker.py (teacher / section / room clashes). */
class ConflictCheckerTest extends TestCase
{
    private Section $sec;

    private Section $sec2;

    private Course $c1;

    private Course $c2;

    private Room $r1;

    private Room $r2;

    private Teacher $t1;

    private Teacher $t2;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::factory()->create();
        $this->sec = Section::factory()->create(['department_id' => $dept->id]);
        $this->sec2 = Section::factory()->create(['department_id' => $dept->id]);
        $this->c1 = Course::factory()->create(['department_id' => $dept->id]);
        $this->c2 = Course::factory()->create(['department_id' => $dept->id]);
        $this->r1 = Room::factory()->create();
        $this->r2 = Room::factory()->create();
        $this->t1 = Teacher::factory()->create();
        $this->t2 = Teacher::factory()->create();
    }

    public function test_free_slot_has_no_conflicts(): void
    {
        $this->entry('Monday', 9, 10);

        $this->assertSame([], ConflictChecker::check([$this->slot('Tuesday', 9, 10)]));    // other day
        $this->assertSame([], ConflictChecker::check([$this->slot('Monday', 10, 11)]));    // touches, no overlap
        $this->assertSame([], ConflictChecker::check([$this->slot('Monday', 9, 10, teacher: $this->t2, section: $this->sec2, room: $this->r2)]));
    }

    public function test_each_shared_resource_is_reported_as_hard(): void
    {
        $e = $this->entry('Monday', 9, 10);

        $found = ConflictChecker::check([$this->slot('Monday', 9, 11)]);
        $this->assertEqualsCanonicalizing(
            [['teacher', $e->id, 'hard'], ['section', $e->id, 'hard'], ['room', $e->id, 'hard']],
            array_map(fn ($c) => [$c['type'], $c['entry_id'], $c['severity']], $found),
        );
        $this->assertSame("room clash with entry {$e->id} on Monday 09:00:00-10:00:00", $found[2]['detail']);

        $onlyRoom = ConflictChecker::check([$this->slot('Monday', 8, 10, teacher: $this->t2, section: $this->sec2)]);
        $this->assertSame([['room', $e->id]], array_map(fn ($c) => [$c['type'], $c['entry_id']], $onlyRoom));
    }

    public function test_partial_overlap_counts(): void
    {
        $e = $this->entry('Monday', 9, 11);

        $found = ConflictChecker::check([$this->slot('Monday', 10, 12, section: $this->sec2, room: $this->r2)]);
        $this->assertSame([['teacher', $e->id]], array_map(fn ($c) => [$c['type'], $c['entry_id']], $found));
    }

    public function test_moved_entries_are_excluded_from_the_existing_rows(): void
    {
        $a = $this->entry('Monday', 9, 10);
        $b = $this->entry('Tuesday', 11, 12, course: $this->c2);

        // a and b trade times: without the exclusion each would clash with its own old row.
        $this->assertSame([], ConflictChecker::check(SwapService::swapSlots($a, $b), [$a->id, $b->id]));
        $this->assertNotSame([], ConflictChecker::check(SwapService::swapSlots($a, $b)));
    }

    public function test_two_proposed_slots_can_clash_with_each_other(): void
    {
        $slots = [
            [...$this->slot('Monday', 9, 10), 'entry_id' => 1],
            [...$this->slot('Monday', 9, 10, teacher: $this->t2, section: $this->sec2), 'entry_id' => 2],   // same room
        ];

        $found = ConflictChecker::check($slots);
        $this->assertSame([['room', 2, 'hard']], array_map(fn ($c) => [$c['type'], $c['entry_id'], $c['severity']], $found));
    }

    public function test_a_missing_teacher_or_room_clashes_with_nothing(): void
    {
        // Imported classes can have no teacher or room; NULL is not "the same teacher".
        $this->entry('Monday', 9, 10)->update(['teacher_id' => null, 'room_id' => null]);   // section sec
        $unknown = ['teacher_id' => null, 'room_id' => null];
        $a = [...$this->slot('Monday', 9, 10, section: $this->sec2), ...$unknown, 'entry_id' => 1];
        $b = [...$this->slot('Monday', 9, 10), ...$unknown, 'section_id' => Section::factory()->create()->id, 'entry_id' => 2];

        $this->assertSame([], ConflictChecker::check([$a]));       // vs the existing row
        $this->assertSame([], ConflictChecker::check([$a, $b]));   // vs each other
    }

    public function test_build_swap_slots_exchanges_time_only(): void
    {
        $a = $this->entry('Monday', 9, 10);
        $b = $this->entry('Tuesday', 11, 12, teacher: $this->t2, section: $this->sec2, room: $this->r2, course: $this->c2);

        [$newA, $newB] = SwapService::swapSlots($a, $b);
        $this->assertSame(['Tuesday', '11:00:00', '12:00:00'], [$newA['day'], $newA['start'], $newA['end']]);
        $this->assertSame([$this->t1->id, $this->sec->id, $this->r1->id, $a->id], [$newA['teacher_id'], $newA['section_id'], $newA['room_id'], $newA['entry_id']]);
        $this->assertSame(['Monday', '09:00:00', '10:00:00'], [$newB['day'], $newB['start'], $newB['end']]);
        $this->assertSame([$this->t2->id, $this->sec2->id, $this->r2->id, $b->id], [$newB['teacher_id'], $newB['section_id'], $newB['room_id'], $newB['entry_id']]);
    }

    private function entry(string $day, int $start, int $end, ?Teacher $teacher = null, ?Section $section = null, ?Room $room = null, ?Course $course = null): TimetableEntry
    {
        return TimetableEntry::factory()->create([
            'section_id' => ($section ?? $this->sec)->id, 'course_id' => ($course ?? $this->c1)->id,
            'teacher_id' => ($teacher ?? $this->t1)->id, 'room_id' => ($room ?? $this->r1)->id,
            'day_of_week' => $day, 'start_time' => sprintf('%02d:00:00', $start), 'end_time' => sprintf('%02d:00:00', $end),
        ]);
    }

    private function slot(string $day, int $start, int $end, ?Teacher $teacher = null, ?Section $section = null, ?Room $room = null): array
    {
        return [
            'day' => $day, 'start' => sprintf('%02d:00:00', $start), 'end' => sprintf('%02d:00:00', $end),
            'teacher_id' => ($teacher ?? $this->t1)->id, 'section_id' => ($section ?? $this->sec)->id,
            'room_id' => ($room ?? $this->r1)->id, 'entry_id' => null,
        ];
    }
}
