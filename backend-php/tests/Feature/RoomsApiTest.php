<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Room;
use App\Models\Section;
use App\Models\TimetableEntry;
use Tests\TestCase;

/** Port of backend/tests/test_rooms_api.py: rooms, empty-room search, departments, courses. */
class RoomsApiTest extends TestCase
{
    private Department $dept;

    private Course $c1;

    private Room $small;

    /** conftest.py's seed: one department, two courses, three rooms. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $this->c1 = Course::factory()->create(['code' => 'CS101', 'title' => 'Programming Fundamentals', 'department_id' => $this->dept->id]);
        Course::factory()->create(['code' => 'SE201', 'title' => 'Software Design', 'department_id' => $this->dept->id]);
        $this->small = Room::factory()->create(['name' => 'R-1', 'capacity' => 30]);
        Room::factory()->create(['name' => 'R-2', 'capacity' => 60]);
        Room::factory()->create(['name' => 'LT-1', 'capacity' => 150]);
    }

    public function test_list_and_filter_rooms(): void
    {
        $this->asRole('student');

        $this->assertSame(['LT-1', 'R-1', 'R-2'], $this->getJson('/api/rooms')->assertOk()->json('*.name'));
        $big = $this->getJson('/api/rooms?min_capacity=50')->json('*.name');
        $this->assertEqualsCanonicalizing(['R-2', 'LT-1'], $big);
        $this->assertSame('LT-1', $this->getJson('/api/rooms?search=lt')->json('0.name'));
        $this->getJson('/api/rooms?min_capacity=0')->assertStatus(422);
    }

    public function test_room_fields_match_room_out(): void
    {
        $this->asRole('student');

        $this->getJson("/api/rooms/{$this->small->id}")->assertOk()
            ->assertExactJson(['id' => $this->small->id, 'name' => 'R-1', 'capacity' => 30, 'building' => null]);
    }

    public function test_get_room_404(): void
    {
        $this->asRole('student');

        $this->getJson('/api/rooms/999')->assertNotFound()->assertExactJson(['detail' => 'Room not found']);
    }

    public function test_rooms_need_a_login(): void
    {
        $this->getJson('/api/rooms')->assertUnauthorized();
        $this->getJson('/api/departments')->assertUnauthorized();
    }

    public function test_create_and_update_room_permissions(): void
    {
        $body = ['name' => 'R-9', 'capacity' => 45, 'building' => 'Block A'];

        $this->asRole('student');
        $this->postJson('/api/rooms', $body)->assertForbidden();
        $this->asRole('teacher');
        $this->postJson('/api/rooms', $body)->assertForbidden();
        $this->asRole('admin');
        $created = $this->postJson('/api/rooms', $body)->assertCreated();
        $this->assertSame(['id' => $created->json('id'), ...$body], $created->json());
        $rid = $created->json('id');

        $this->asRole('hod');
        $this->postJson('/api/rooms', $body)->assertStatus(409)
            ->assertExactJson(['detail' => 'A room with this name already exists']);
        $this->putJson("/api/rooms/$rid", ['capacity' => 80])->assertOk()->assertJsonPath('capacity', 80);
        $this->putJson("/api/rooms/$rid", ['name' => 'R-1'])->assertStatus(409);
        $this->putJson("/api/rooms/$rid", ['name' => 'R-9'])->assertOk();   // its own name is fine
        $this->postJson('/api/rooms', ['name' => 'X', 'capacity' => 0])->assertStatus(422);
        $this->putJson('/api/rooms/999', ['capacity' => 10])->assertNotFound();
    }

    public function test_update_changes_only_the_fields_sent(): void
    {
        $room = Room::factory()->create(['name' => 'B-1', 'capacity' => 40, 'building' => 'Block B']);
        $this->asRole('admin');

        $this->putJson("/api/rooms/{$room->id}", ['building' => null])->assertOk()
            ->assertJson(['name' => 'B-1', 'capacity' => 40, 'building' => null]);
        $this->putJson("/api/rooms/{$room->id}", ['capacity' => null])->assertOk()->assertJsonPath('capacity', null);
        $this->putJson("/api/rooms/{$room->id}", ['name' => null])->assertStatus(422);
    }

    public function test_empty_rooms_respects_overlap_boundaries(): void
    {
        // R-1 is busy Monday 10:00-11:00
        TimetableEntry::factory()->create([
            'section_id' => Section::factory()->create(['department_id' => $this->dept->id])->id,
            'course_id' => $this->c1->id, 'room_id' => $this->small->id, 'teacher_id' => null,
            'day_of_week' => 'Monday', 'start_time' => '10:00:00', 'end_time' => '11:00:00',
        ]);
        $this->asRole('student');

        $free = function (string $day, string $start, string $end, array $extra = []): array {
            $query = http_build_query(['day' => $day, 'start' => $start, 'end' => $end, ...$extra]);
            $names = $this->getJson("/api/rooms/empty?$query")->assertOk()->json('*.name');
            sort($names);

            return $names;
        };

        $this->assertSame(['LT-1', 'R-2'], $free('Monday', '10:30', '11:30'));          // overlaps R-1
        $this->assertSame(['LT-1', 'R-2'], $free('Monday', '09:00', '10:30'));          // overlaps at the start
        $this->assertSame(['LT-1', 'R-1', 'R-2'], $free('Monday', '11:00', '12:00'));   // starts exactly when it ends
        $this->assertSame(['LT-1', 'R-1', 'R-2'], $free('Monday', '09:00', '10:00'));   // ends exactly when it starts
        $this->assertSame(['LT-1', 'R-1', 'R-2'], $free('Tuesday', '10:00', '11:00'));  // other day
        $this->assertSame(['LT-1'], $free('mon', '10:00', '11:00', ['min_capacity' => 100]));
    }

    public function test_empty_rooms_validation(): void
    {
        $this->asRole('student');

        $this->getJson('/api/rooms/empty?day=Funday&start=9:00&end=10:00')->assertStatus(422)
            ->assertExactJson(['detail' => "Unrecognised day: 'Funday'"]);
        $this->getJson('/api/rooms/empty?day=Monday&start=xx&end=10:00')->assertStatus(422)
            ->assertExactJson(['detail' => 'start and end must look like HH:MM']);
        $this->getJson('/api/rooms/empty?day=Monday&start=11:00&end=10:00')->assertStatus(422)
            ->assertExactJson(['detail' => 'end must be after start']);
        $this->getJson('/api/rooms/empty?day=Monday&start=09:00')->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['query', 'end']);
    }

    public function test_empty_rooms_lists_unknown_capacity_last(): void
    {
        Room::factory()->create(['name' => 'A-Unknown', 'capacity' => null]);
        $this->asRole('student');

        $rooms = $this->getJson('/api/rooms/empty?day=Monday&start=09:00&end=10:00')->assertOk()->json();
        $this->assertSame(['R-1', 'R-2', 'LT-1', 'A-Unknown'], array_column($rooms, 'name'));
        $this->assertNull(end($rooms)['capacity']);
    }

    public function test_departments_and_courses(): void
    {
        $this->asRole('student');

        $this->assertSame('SE', $this->getJson('/api/departments')->json('0.code'));
        $this->assertCount(2, $this->getJson('/api/courses')->json());
        $this->assertSame(['SE201'], $this->getJson('/api/courses?search=design')->json('*.code'));
        $this->assertCount(2, $this->getJson("/api/courses?department_id={$this->dept->id}")->json());
        $this->assertSame([], $this->getJson('/api/courses?department_id=999')->json());
        $this->getJson('/api/courses?search=cs1')->assertExactJson([[
            'id' => $this->c1->id, 'code' => 'CS101', 'title' => 'Programming Fundamentals',
            'credit_hours' => 3, 'department_id' => $this->dept->id,
        ]]);
    }
}
