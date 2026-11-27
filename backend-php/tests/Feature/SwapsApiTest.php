<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Room;
use App\Models\Section;
use App\Models\SwapRequest;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Port of backend/tests/test_swaps_api.py. Two departments; teacher t1 (user
 * "teacher") owns e1, e2 and e5 in SE:
 *
 *   e1  t1  secA  c1  r1  Monday    09-10
 *   e2  t1  secA  c2  r1  Tuesday   11-12
 *   e3  t2  secB  c1  r2  Wednesday 09-10   (another teacher's class)
 *   e4  t2  secB  c2  r1  Tuesday   11-12   (r1 busy when e1 takes e2's slot: hard clash)
 *   e5  t1  secA  c1  r1  Thursday  09-10   (a second session of e1's class)
 *   e6  t2  secB  c1  r2  Friday    09-10   (a clash-free partner for e4)
 */
class SwapsApiTest extends TestCase
{
    /** @var array<string, User> */
    private array $users;

    /** @var array<string, TimetableEntry> */
    private array $e;

    private Section $secB;

    private Course $c2;

    private Teacher $t2;

    private Room $r2;

    protected function setUp(): void
    {
        parent::setUp();

        $se = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $cs = Department::factory()->create(['name' => 'Computer Science', 'code' => 'CS']);
        $this->users = [
            'teacher' => User::factory()->role('teacher')->create(['department_id' => $se->id]),
            'teacher2' => User::factory()->role('teacher')->create(['department_id' => $se->id]),
            'hod' => User::factory()->role('hod')->create(['department_id' => $se->id]),
            'hod_cs' => User::factory()->role('hod')->create(['department_id' => $cs->id]),
            'admin' => User::factory()->role('admin')->create(),
        ];
        $secA = Section::factory()->create(['name' => 'BSSE-5A', 'department_id' => $se->id]);
        $this->secB = Section::factory()->create(['name' => 'BSSE-5B', 'department_id' => $se->id]);
        $this->users['student'] = User::factory()->role('student')->create(['section_id' => $secA->id]);

        $c1 = Course::factory()->create(['code' => 'SE301', 'department_id' => $se->id]);
        $this->c2 = Course::factory()->create(['code' => 'SE302', 'department_id' => $se->id]);
        $t1 = Teacher::factory()->create(['user_id' => $this->users['teacher']->id, 'department_id' => $se->id]);
        $this->t2 = Teacher::factory()->create(['user_id' => $this->users['teacher2']->id, 'department_id' => $se->id]);
        $r1 = Room::factory()->create();
        $this->r2 = Room::factory()->create();

        $this->e = [
            'e1' => $this->entry($secA, $c1, $t1, $r1, 'Monday', 9, 10),
            'e2' => $this->entry($secA, $this->c2, $t1, $r1, 'Tuesday', 11, 12),
            'e3' => $this->entry($this->secB, $c1, $this->t2, $this->r2, 'Wednesday', 9, 10),
            'e4' => $this->entry($this->secB, $this->c2, $this->t2, $r1, 'Tuesday', 11, 12),
            'e5' => $this->entry($secA, $c1, $t1, $r1, 'Thursday', 9, 10),
            'e6' => $this->entry($this->secB, $c1, $this->t2, $this->r2, 'Friday', 9, 10),
        ];
    }

    // ------------------------------------------------------------ create

    public function test_teacher_creates_swap_for_own_class(): void
    {
        $r = $this->create(reason: 'Conference on Monday')->assertCreated();

        $this->assertSame('pending', $r->json('status'));
        $this->assertSame([], $r->json('warnings'));
        $this->assertSame([$this->e['e1']->id, $this->e['e3']->id], [$r->json('entry_id'), $r->json('target_entry_id')]);
        $this->assertSame('Conference on Monday', $r->json('reason'));
        // both teachers and the students of both sections are told
        $this->assertEqualsCanonicalizing(
            [$this->users['teacher']->id, $this->users['teacher2']->id, $this->users['student']->id],
            Notification::pluck('user_id')->all(),
        );
        $this->assertSame(['swap_requested'], Notification::distinct()->pluck('type')->all());
    }

    public function test_created_request_has_every_field(): void
    {
        $body = $this->create()->json();

        $this->assertSame(['id', 'requester_teacher_id', 'entry_id', 'target_entry_id', 'status', 'reason',
            'decided_by', 'decided_at', 'decision_note', 'created_at', 'warnings'], array_keys($body));
        $this->assertSame($this->e['e1']->teacher_id, $body['requester_teacher_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $body['created_at']);
    }

    public function test_create_permission_refusals(): void
    {
        $this->create(who: 'teacher2')->assertForbidden()   // not the owner of e1
            ->assertExactJson(['detail' => 'You can only request swaps for your own classes']);
        $this->create(who: 'student')->assertForbidden();   // wrong role
        $this->create(who: 'hod')->assertForbidden();
    }

    public function test_create_invalid_states(): void
    {
        $this->create(target: 'e1')->assertStatus(400)->assertExactJson(['detail' => 'A class cannot be swapped with itself']);
        $this->asUser($this->users['teacher'])->postJson('/api/swap-requests', ['entry_id' => $this->e['e1']->id, 'target_entry_id' => 999])
            ->assertNotFound()->assertExactJson(['detail' => 'Timetable entry not found']);
        $this->asUser($this->users['teacher'])->postJson('/api/swap-requests', ['entry_id' => $this->e['e1']->id])
            ->assertStatus(422)->assertJsonPath('detail.0.loc', ['body', 'target_entry_id']);
        $this->create()->assertCreated();
        $this->create()->assertStatus(409);   // a pending request already on e1/e3
        $this->assertStringContainsString('pending', $this->create(entry: 'e5', target: 'e3')->json('detail'));
    }

    public function test_create_rejects_swapping_two_sessions_of_the_same_class(): void
    {
        $r = $this->create(entry: 'e1', target: 'e5')->assertStatus(400);
        $this->assertStringContainsString('same course', $r->json('detail'));
    }

    public function test_hard_clash_is_refused_with_conflict_list(): void
    {
        // e1 would move to Tuesday 11-12 in R-1, but e4 already has R-1 then.
        $detail = $this->create(entry: 'e1', target: 'e2')->assertStatus(409)->json('detail');

        $this->assertSame('Swap would cause scheduling conflicts', $detail['message']);
        $kinds = array_map(fn ($c) => [$c['type'], $c['entry_id'], $c['severity']], $detail['conflicts']);
        $this->assertContains(['room', $this->e['e4']->id, 'hard'], $kinds);
        $this->assertSame(['type', 'entry_id', 'detail', 'severity'], array_keys($detail['conflicts'][0]));
        $this->assertSame(0, SwapRequest::count());   // nothing stored
    }

    // ------------------------------------------------------------ approve

    public function test_hod_of_same_department_approves_and_times_are_exchanged(): void
    {
        $sid = $this->create()->json('id');

        $r = $this->as('hod')->postJson("/api/swap-requests/$sid/approve", ['note' => 'ok'])->assertOk();
        $this->assertSame(['approved', $this->users['hod']->id, 'ok'], [$r->json('status'), $r->json('decided_by'), $r->json('decision_note')]);
        $this->assertNotNull($r->json('decided_at'));

        $a = $this->e['e1']->fresh();
        $b = $this->e['e3']->fresh();
        $this->assertSame(['Wednesday', '09:00:00', '10:00:00'], [$a->day_of_week, $a->start_time, $a->end_time]);
        $this->assertSame(['Monday', '09:00:00', '10:00:00'], [$b->day_of_week, $b->start_time, $b->end_time]);
        // owners, sections and rooms stay put: only the time moved
        $this->assertNotSame($a->teacher_id, $b->teacher_id);
        $this->assertNotSame($a->room_id, $b->room_id);
        $this->assertSame(3, Notification::where('type', 'swap_approved')->count());
        // cannot be decided twice
        $this->as('hod')->postJson("/api/swap-requests/$sid/approve")->assertStatus(409)
            ->assertExactJson(['detail' => 'Swap request is already approved']);
    }

    public function test_approve_permission_refusals(): void
    {
        $sid = $this->create()->json('id');

        $this->as('teacher')->postJson("/api/swap-requests/$sid/approve")->assertForbidden();   // role
        $this->as('hod_cs')->postJson("/api/swap-requests/$sid/approve")->assertForbidden()     // other department
            ->assertExactJson(['detail' => 'You may not decide this swap request']);
        $this->as('hod')->postJson('/api/swap-requests/999/approve')->assertNotFound()
            ->assertExactJson(['detail' => 'Swap request not found']);
        $this->assertSame('Monday', $this->e['e1']->fresh()->day_of_week);                       // untouched
        $this->as('admin')->postJson("/api/swap-requests/$sid/approve")->assertOk();             // admin: any department
    }

    public function test_approve_rechecks_conflicts_at_decision_time(): void
    {
        $sid = $this->create()->json('id');   // e1 <-> e3, clean at request time
        // R-2 becomes busy on Monday 09-10, so e3 can no longer move into e1's slot.
        $this->entry($this->secB, $this->c2, $this->t2, $this->r2, 'Monday', 9, 10);

        $r = $this->as('hod')->postJson("/api/swap-requests/$sid/approve")->assertStatus(409);
        $this->assertContains('room', array_column($r->json('detail.conflicts'), 'type'));
        $this->assertSame('pending', $this->as('hod')->getJson('/api/swap-requests')->json('0.status'));
        $this->assertSame('Monday', $this->e['e1']->fresh()->day_of_week);
        $this->assertSame(0, Notification::where('type', 'swap_approved')->count());   // rolled back too
    }

    // ------------------------------------------------------------ reject / cancel

    public function test_reject_needs_a_note_and_leaves_timetable_alone(): void
    {
        $sid = $this->create()->json('id');

        $this->as('hod')->postJson("/api/swap-requests/$sid/reject", [])->assertStatus(422);
        $this->as('hod')->postJson("/api/swap-requests/$sid/reject", ['note' => '   '])->assertStatus(422);
        $this->as('hod_cs')->postJson("/api/swap-requests/$sid/reject", ['note' => 'no'])->assertForbidden();
        $r = $this->as('hod')->postJson("/api/swap-requests/$sid/reject", ['note' => '  Room needed '])->assertOk();
        $this->assertSame(['rejected', 'Room needed'], [$r->json('status'), $r->json('decision_note')]);
        $this->assertSame('Monday', $this->e['e1']->fresh()->day_of_week);
        $this->assertSame(3, Notification::where('type', 'swap_rejected')->count());
        $this->as('hod')->postJson("/api/swap-requests/$sid/approve")->assertStatus(409);   // already rejected
    }

    public function test_cancel_is_for_the_requester_while_pending(): void
    {
        $sid = $this->create()->json('id');

        $this->as('teacher2')->postJson("/api/swap-requests/$sid/cancel")->assertForbidden()
            ->assertExactJson(['detail' => 'Only the requester can cancel this swap request']);
        $this->as('hod')->postJson("/api/swap-requests/$sid/cancel")->assertForbidden();   // role
        $this->as('teacher')->postJson("/api/swap-requests/$sid/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->as('teacher')->postJson("/api/swap-requests/$sid/cancel")->assertStatus(409);
        $this->as('hod')->postJson("/api/swap-requests/$sid/approve")->assertStatus(409);
        $this->create()->assertCreated();   // the classes are free again
    }

    // ------------------------------------------------------------ list

    public function test_list_is_scoped_by_role(): void
    {
        $mine = $this->create()->json('id');                                                   // t1: e1 <-> e3
        $theirs = $this->create(who: 'teacher2', entry: 'e4', target: 'e6')->json('id');       // t2: e4 <-> e6
        $this->as('hod')->postJson("/api/swap-requests/$theirs/reject", ['note' => 'no'])->assertOk();

        $ids = fn (string $who) => array_column($this->as($who)->getJson('/api/swap-requests')->assertOk()->json(), 'id');
        $this->assertSame([$mine], $ids('teacher'));
        $this->assertSame([$theirs], $ids('teacher2'));
        $this->assertSame([$mine], $ids('hod'));      // pending only
        $this->assertSame([], $ids('hod_cs'));        // other department
        $this->assertSame([$mine], $ids('admin'));
        $this->as('student')->getJson('/api/swap-requests')->assertForbidden();
    }

    // ------------------------------------------------------------ unlinked entries and teachers

    public function test_swap_for_an_entry_with_no_teacher_is_refused_not_a_crash(): void
    {
        $orphan = $this->entry($this->secB, $this->c2, $this->t2, $this->r2, 'Thursday', 14, 15);
        $orphan->update(['teacher_id' => null]);   // as an import row with a blank teacher cell leaves it

        $this->as('teacher')->postJson('/api/swap-requests', ['entry_id' => $orphan->id, 'target_entry_id' => $this->e['e3']->id])
            ->assertForbidden();
    }

    public function test_swap_with_a_teacher_who_has_no_login_still_works(): void
    {
        $this->t2->update(['user_id' => null]);   // imported teacher, account not linked yet

        $this->create()->assertCreated();
        $this->assertEqualsCanonicalizing([$this->users['teacher']->id, $this->users['student']->id], Notification::pluck('user_id')->all());
    }

    // ------------------------------------------------------------ helpers

    private function entry(Section $section, Course $course, Teacher $teacher, Room $room, string $day, int $start, int $end): TimetableEntry
    {
        return TimetableEntry::factory()->create([
            'section_id' => $section->id, 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'room_id' => $room->id,
            'day_of_week' => $day, 'start_time' => sprintf('%02d:00:00', $start), 'end_time' => sprintf('%02d:00:00', $end),
        ]);
    }

    private function as(string $who): static
    {
        return $this->asUser($this->users[$who]);
    }

    private function create(string $who = 'teacher', string $entry = 'e1', string $target = 'e3', ?string $reason = null): TestResponse
    {
        return $this->as($who)->postJson('/api/swap-requests', array_filter([
            'entry_id' => $this->e[$entry]->id, 'target_entry_id' => $this->e[$target]->id, 'reason' => $reason,
        ], fn ($v) => $v !== null));
    }
}
