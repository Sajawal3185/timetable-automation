<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Database\Factories\UserFactory;
use Tests\TestCase;

/**
 * Port of backend/tests/test_events_api.py. Real tokens through /api/auth/login, like AuthApiTest.
 * (Like AuthApiTest this has no RefreshDatabase line: if your other feature tests have one, add it.)
 */
class EventsApiTest extends TestCase
{
    private Department $dept;

    /** @var array<string,string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        User::factory()->role('student')->create(['identifier' => 'se-123456', 'name' => 'STUDENT']);
        User::factory()->role('teacher')->create(['identifier' => 't-ali', 'name' => 'TEACHER', 'department_id' => $this->dept->id]);
        User::factory()->role('hod')->create(['identifier' => 'h-sara', 'name' => 'HOD', 'department_id' => $this->dept->id]);
        User::factory()->role('admin')->create(['identifier' => 'a-root', 'name' => 'ADMIN']);
    }

    private const WHO = ['student' => 'se-123456', 'teacher' => 't-ali', 'hod' => 'h-sara', 'admin' => 'a-root'];

    /** Acts as that role (token fetched once, so the login limit is never hit). */
    private function as(string $role): static
    {
        $identifier = self::WHO[$role];
        $this->tokens[$role] ??= $this->postJson('/api/auth/login', [
            'identifier' => $identifier, 'password' => UserFactory::PASSWORD,
        ])->json('token');

        return $this->withToken($this->tokens[$role]);
    }

    private function ev(array $over = []): array
    {
        return [...['title' => 'Midterm exams', 'event_type' => 'deadline', 'start_date' => '2026-10-20'], ...$over];
    }

    public function test_event_crud_and_permissions(): void
    {
        $this->as('student')->postJson('/api/events', $this->ev())->assertStatus(403);
        $this->as('teacher')->postJson('/api/events', $this->ev())->assertStatus(403);

        $r = $this->as('hod')->postJson('/api/events', $this->ev(['end_date' => '2026-10-25', 'location' => 'Main hall']))
            ->assertStatus(201);
        $eid = $r->json('id');

        $this->assertSame('Main hall', $this->as('student')->getJson("/api/events/$eid")->json('location'));
        $this->assertSame('Mid-terms', $this->as('admin')->putJson("/api/events/$eid", ['title' => 'Mid-terms'])->json('title'));
        $this->as('admin')->putJson("/api/events/$eid", ['end_date' => '2026-10-01'])->assertStatus(422);
        // a failed update changed nothing
        $this->assertSame('2026-10-25', $this->as('student')->getJson("/api/events/$eid")->json('end_date'));
        $this->as('student')->deleteJson("/api/events/$eid")->assertStatus(403);
        $this->as('admin')->deleteJson("/api/events/$eid")->assertStatus(204);
        $this->as('student')->getJson("/api/events/$eid")->assertStatus(404);
    }

    public function test_event_validation(): void
    {
        $a = $this->as('admin');
        $a->postJson('/api/events', $this->ev(['end_date' => '2026-10-01']))->assertStatus(422);
        $this->as('admin')->postJson('/api/events', $this->ev(['event_type' => 'party']))->assertStatus(422);
        $this->as('admin')->postJson('/api/events', $this->ev(['department_id' => 999]))->assertStatus(404);
    }

    public function test_event_month_and_range_filters(): void
    {
        $this->as('admin')->postJson('/api/events', $this->ev(['title' => 'Oct deadline', 'start_date' => '2026-10-20']))->assertStatus(201);
        $this->as('admin')->postJson('/api/events', $this->ev([
            'title' => 'Winter break', 'event_type' => 'holiday', 'start_date' => '2026-12-28', 'end_date' => '2027-01-05',
        ]))->assertStatus(201);
        $this->as('admin')->postJson('/api/events', $this->ev([
            'title' => 'SE seminar', 'event_type' => 'department', 'start_date' => '2027-01-02', 'department_id' => $this->dept->id,
        ]))->assertStatus(201);

        $titles = fn (array $p) => array_column(
            $this->as('student')->getJson('/api/events?'.http_build_query($p))->assertOk()->json(), 'title'
        );

        $this->assertSame(['Oct deadline'], $titles(['month' => '2026-10']));
        $this->assertSame(['Winter break'], $titles(['month' => '2026-12']));                  // starts in Dec
        $this->assertSame(['Winter break', 'SE seminar'], $titles(['month' => '2027-01']));    // spans into Jan
        $this->assertSame([], $titles(['month' => '2026-11']));
        $this->assertSame(['Winter break', 'SE seminar'], $titles(['from_date' => '2026-10-21']));
        $this->assertSame([], $titles(['to_date' => '2026-10-19']));
        $this->assertSame(['Winter break'], $titles(['event_type' => 'holiday']));
        // + the university-wide events (no department)
        $this->assertSame(['Oct deadline', 'Winter break', 'SE seminar'], $titles(['department_id' => $this->dept->id]));

        $s = fn (string $query) => $this->as('student')->getJson("/api/events?$query");
        $s('month=2026-13')->assertStatus(422);
        $s('month=bad')->assertStatus(422);
        $s('from_date=2026-10-10&to_date=2026-10-01')->assertStatus(422);
    }

    public function test_event_update_is_put_with_partial_body(): void
    {
        // Contract: events are updated with PUT (like rooms and software), not PATCH.
        $eid = $this->as('admin')->postJson('/api/events', $this->ev(['location' => 'Main hall']))->json('id');

        $this->as('admin')->patchJson("/api/events/$eid", ['title' => 'x'])->assertStatus(405);

        $r = $this->as('admin')->putJson("/api/events/$eid", ['title' => 'Only the title'])->assertOk();
        $body = $r->json();
        $this->assertSame('Only the title', $body['title']);
        $this->assertSame('2026-10-20', $body['start_date']);
        $this->assertSame('Main hall', $body['location']);   // untouched fields stay
    }

    // ---- extra cases (not in the Python file, but they are its behaviour)

    public function test_events_need_a_login(): void
    {
        $this->getJson('/api/events')->assertStatus(401);
        $this->getJson('/api/events/1')->assertStatus(401);
        $this->postJson('/api/events', $this->ev())->assertStatus(401);
    }

    public function test_missing_event_and_unknown_department_messages(): void
    {
        $this->as('student')->getJson('/api/events/9999')->assertStatus(404)->assertExactJson(['detail' => 'Event not found']);
        $this->as('admin')->putJson('/api/events/9999', ['title' => 'x'])->assertStatus(404);
        $this->as('admin')->deleteJson('/api/events/9999')->assertStatus(404);

        $eid = $this->as('admin')->postJson('/api/events', $this->ev())->json('id');
        $this->as('admin')->putJson("/api/events/$eid", ['department_id' => 999])
            ->assertStatus(404)->assertExactJson(['detail' => 'Department not found']);
    }

    public function test_update_cannot_set_required_fields_to_null(): void
    {
        $eid = $this->as('admin')->postJson('/api/events', $this->ev())->json('id');

        foreach (['title', 'event_type', 'start_date'] as $field) {
            $this->as('admin')->putJson("/api/events/$eid", [$field => null])
                ->assertStatus(422)->assertExactJson(['detail' => "$field cannot be null"]);
        }
        // optional fields can be cleared
        $this->as('admin')->putJson("/api/events/$eid", ['location' => null])->assertOk()->assertJsonPath('location', null);
    }

    public function test_moving_the_start_after_the_end_is_refused(): void
    {
        $eid = $this->as('admin')->postJson('/api/events', $this->ev(['end_date' => '2026-10-25']))->json('id');

        $this->as('admin')->putJson("/api/events/$eid", ['start_date' => '2026-11-01'])
            ->assertStatus(422)->assertExactJson(['detail' => 'end_date cannot be before start_date']);
        $this->assertSame('2026-10-20', $this->as('student')->getJson("/api/events/$eid")->json('start_date'));
    }
}
