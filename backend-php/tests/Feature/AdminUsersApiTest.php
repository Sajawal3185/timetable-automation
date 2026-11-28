<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\User;
use Tests\TestCase;

/** GET/PUT /api/admin/users, GET /api/sections, GET /api/teachers (new in PHP, "Fully Working Website" 5-C). */
class AdminUsersApiTest extends TestCase
{
    private Department $se;

    private Department $cs;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->se = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $this->cs = Department::factory()->create(['name' => 'Computer Science', 'code' => 'CS']);
        $this->admin = User::factory()->role('admin')->create(['name' => 'Admin']);
    }

    // ------------------------------------------------------------ list

    public function test_admin_lists_users_with_filters_and_paging(): void
    {
        $linked = User::factory()->role('teacher')->create(['name' => 'B Linked', 'department_id' => $this->se->id, 'email' => 'b@numls.edu.pk']);
        Teacher::factory()->create(['user_id' => $linked->id, 'department_id' => $this->se->id]);
        $waiting = User::factory()->role('teacher')->create(['name' => 'A Waiting', 'department_id' => $this->se->id]);
        User::factory()->role('student')->create(['name' => 'C Student', 'department_id' => $this->cs->id]);
        $this->asUser($this->admin);

        $all = $this->getJson('/api/admin/users')->assertOk()->assertHeader('X-Total-Count', '4');
        $this->assertSame(['A Waiting', 'Admin', 'B Linked', 'C Student'], array_column($all->json(), 'name'));
        $this->assertSame(['id', 'name', 'role', 'identifier', 'department_id', 'section_id', 'teacher_id', 'email', 'email_verified', 'is_active'],
            array_keys($all->json(0)));
        $this->assertArrayNotHasKey('password_hash', $all->json(0));

        $this->assertSame(['A Waiting', 'B Linked'], array_column($this->getJson('/api/admin/users?role=teacher')->json(), 'name'));
        $this->assertSame(['C Student'], array_column($this->getJson("/api/admin/users?department_id={$this->cs->id}")->json(), 'name'));
        // staff not linked yet: the ones the admin has to link (the admin has no teacher record either)
        $this->assertSame([$waiting->id, $this->admin->id], array_column($this->getJson('/api/admin/users?unlinked=true')->json(), 'id'));
        $this->assertCount(1, $this->getJson('/api/admin/users?limit=1&skip=1')->json());
        $this->getJson('/api/admin/users?role=boss')->assertStatus(422);
    }

    public function test_only_admins_use_the_users_screen(): void
    {
        $target = User::factory()->role('teacher')->create();

        foreach (['hod', 'teacher', 'student'] as $role) {
            $this->asRole($role, ['department_id' => $this->se->id]);
            $this->getJson('/api/admin/users')->assertForbidden();
            $this->putJson("/api/admin/users/{$target->id}", ['role' => 'admin'])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    // ------------------------------------------------------------ link a teacher record

    public function test_admin_links_a_signed_up_teacher_to_their_teacher_record(): void
    {
        $user = User::factory()->role('teacher')->create(['department_id' => $this->se->id]);
        $record = Teacher::factory()->create(['name' => 'Dr. Ali', 'department_id' => $this->se->id]);
        $this->asUser($this->admin);

        $this->putJson("/api/admin/users/{$user->id}", ['teacher_id' => $record->id])->assertOk()
            ->assertJsonPath('teacher_id', $record->id);
        $this->assertSame($user->id, $record->fresh()->user_id);

        // /api/auth/me now gives the teacher_id the timetable uses for "my classes"
        $this->asUser($user);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('teacher_id', $record->id);
    }

    public function test_relinking_moves_the_account_and_null_unlinks(): void
    {
        $user = User::factory()->role('teacher')->create();
        [$first, $second] = Teacher::factory()->count(2)->create()->all();
        $this->asUser($this->admin);

        $this->putJson("/api/admin/users/{$user->id}", ['teacher_id' => $first->id])->assertOk();
        $this->putJson("/api/admin/users/{$user->id}", ['teacher_id' => $second->id])->assertOk()->assertJsonPath('teacher_id', $second->id);
        $this->assertNull($first->fresh()->user_id);   // one account, one record

        $this->putJson("/api/admin/users/{$user->id}", ['teacher_id' => null])->assertOk()->assertJsonPath('teacher_id', null);
        $this->assertNull($second->fresh()->user_id);
    }

    public function test_a_teacher_record_belongs_to_one_account(): void
    {
        $owner = User::factory()->role('teacher')->create();
        $other = User::factory()->role('teacher')->create();
        $record = Teacher::factory()->create(['user_id' => $owner->id]);
        $this->asUser($this->admin);

        $this->putJson("/api/admin/users/{$other->id}", ['teacher_id' => $record->id])->assertStatus(409)
            ->assertExactJson(['detail' => 'That teacher record is already linked to another account']);
        $this->putJson("/api/admin/users/{$owner->id}", ['teacher_id' => $record->id])->assertOk();   // same link again: fine
        $this->putJson("/api/admin/users/{$other->id}", ['teacher_id' => 999])->assertStatus(400)->assertExactJson(['detail' => 'Teacher not found']);
    }

    public function test_students_cannot_be_linked_or_promoted(): void
    {
        $student = User::factory()->role('student')->create();
        $record = Teacher::factory()->create();
        $this->asUser($this->admin);

        $this->putJson("/api/admin/users/{$student->id}", ['teacher_id' => $record->id])->assertStatus(400)
            ->assertExactJson(['detail' => 'Only staff accounts can be linked to a teacher record']);
        $this->putJson("/api/admin/users/{$student->id}", ['role' => 'admin'])->assertStatus(400);
        $this->assertSame('student', $student->fresh()->role);
        $this->assertNull($record->fresh()->user_id);
    }

    // ------------------------------------------------------------ roles and departments

    public function test_admin_makes_a_teacher_hod_of_a_department(): void
    {
        $user = User::factory()->role('teacher')->create();
        $this->asUser($this->admin);

        $this->putJson("/api/admin/users/{$user->id}", ['role' => 'hod'])->assertStatus(400)
            ->assertExactJson(['detail' => 'department_id is required when role is hod']);
        $this->putJson("/api/admin/users/{$user->id}", ['role' => 'hod', 'department_id' => $this->se->id])->assertOk()
            ->assertJsonPath('role', 'hod')->assertJsonPath('department_id', $this->se->id);
        $this->putJson("/api/admin/users/{$user->id}", ['department_id' => null])->assertStatus(400);   // a HOD keeps one
        $this->putJson("/api/admin/users/{$user->id}", ['department_id' => 999])->assertStatus(400)->assertExactJson(['detail' => 'Department not found']);
        $this->putJson("/api/admin/users/{$user->id}", ['role' => 'student'])->assertStatus(400);
        $this->putJson("/api/admin/users/{$user->id}", ['role' => 'admin'])->assertOk()->assertJsonPath('role', 'admin');
        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_nobody_changes_their_own_role(): void
    {
        $this->asUser($this->admin);

        $this->putJson("/api/admin/users/{$this->admin->id}", ['role' => 'teacher'])->assertStatus(400)
            ->assertExactJson(['detail' => "You can't change your own role"]);
        $this->putJson("/api/admin/users/{$this->admin->id}", ['role' => 'admin'])->assertOk();   // no change: fine
        $this->putJson("/api/admin/users/{$this->admin->id}", ['department_id' => $this->cs->id])->assertOk();
    }

    public function test_promotion_takes_effect_on_the_next_request(): void
    {
        $user = User::factory()->role('teacher')->create(['department_id' => $this->se->id]);
        $token = $this->tokenFor($user);
        $this->asUser($this->admin)->putJson("/api/admin/users/{$user->id}", ['role' => 'admin'])->assertOk();

        // The role comes from the database, not the token: the old token now opens admin pages.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/users')->assertOk();
    }

    public function test_update_errors(): void
    {
        $this->asUser($this->admin);

        $this->putJson('/api/admin/users/999', ['role' => 'admin'])->assertNotFound()->assertExactJson(['detail' => 'User not found']);
        $this->putJson("/api/admin/users/{$this->admin->id}", ['teacher_id' => ''])->assertStatus(422);
    }

    // ------------------------------------------------------------ sections and teachers

    public function test_sections_list_for_any_user(): void
    {
        $b = Section::factory()->create(['name' => 'BSSE-5B', 'department_id' => $this->se->id, 'student_count' => 38]);
        $a = Section::factory()->create(['name' => 'BSSE-5A', 'department_id' => $this->se->id, 'student_count' => 45]);
        Section::factory()->create(['name' => 'BSCS-3A', 'department_id' => $this->cs->id]);
        $this->asRole('student');

        $this->assertSame(['BSCS-3A', 'BSSE-5A', 'BSSE-5B'], array_column($this->getJson('/api/sections')->assertOk()->json(), 'name'));
        $this->getJson("/api/sections?department_id={$this->se->id}")->assertExactJson([
            ['id' => $a->id, 'name' => 'BSSE-5A', 'department_id' => $this->se->id, 'student_count' => 45],
            ['id' => $b->id, 'name' => 'BSSE-5B', 'department_id' => $this->se->id, 'student_count' => 38],
        ]);
    }

    public function test_teachers_list_for_any_user(): void
    {
        $user = User::factory()->role('teacher')->create();
        $linked = Teacher::factory()->create(['name' => 'Dr. Ali', 'department_id' => $this->se->id, 'user_id' => $user->id]);
        $free = Teacher::factory()->create(['name' => 'Mr. Bilal', 'department_id' => $this->se->id]);
        Teacher::factory()->create(['name' => 'Ms. Hina', 'department_id' => $this->cs->id]);
        $this->asRole('student');

        $this->assertSame(['Dr. Ali', 'Mr. Bilal', 'Ms. Hina'], array_column($this->getJson('/api/teachers')->assertOk()->json(), 'name'));
        $this->getJson("/api/teachers?department_id={$this->se->id}")->assertExactJson([
            ['id' => $linked->id, 'name' => 'Dr. Ali', 'department_id' => $this->se->id, 'user_id' => $user->id],
            ['id' => $free->id, 'name' => 'Mr. Bilal', 'department_id' => $this->se->id, 'user_id' => null],
        ]);
        $this->assertSame(['Mr. Bilal', 'Ms. Hina'], array_column($this->getJson('/api/teachers?unlinked=true')->json(), 'name'));
    }

    public function test_sections_and_teachers_need_a_login(): void
    {
        $this->getJson('/api/sections')->assertUnauthorized();
        $this->getJson('/api/teachers')->assertUnauthorized();
    }
}
