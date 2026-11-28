<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\User;
use Database\Factories\UserFactory;
use Tests\TestCase;

/**
 * Port of backend/tests/test_software_api.py. Real tokens through /api/auth/login, like EventsApiTest.
 */
class SoftwareApiTest extends TestCase
{
    private Department $dept;

    private Course $course;

    /** @var array<string,string> */
    private array $tokens = [];

    private const SW = [
        'name' => 'Visual Studio Code',
        'category' => 'IDE',
        'download_url' => 'https://code.visualstudio.com',
        'instructions' => 'Install with defaults.',
    ];

    private const WHO = ['student' => 'se-123456', 'teacher' => 't-ali', 'hod' => 'h-sara', 'admin' => 'a-root'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $this->course = Course::factory()->create(['department_id' => $this->dept->id]);

        User::factory()->role('student')->create(['identifier' => 'se-123456', 'name' => 'STUDENT']);
        User::factory()->role('teacher')->create(['identifier' => 't-ali', 'name' => 'TEACHER', 'department_id' => $this->dept->id]);
        User::factory()->role('hod')->create(['identifier' => 'h-sara', 'name' => 'HOD', 'department_id' => $this->dept->id]);
        User::factory()->role('admin')->create(['identifier' => 'a-root', 'name' => 'ADMIN']);
    }

    private function as(string $role): static
    {
        $this->tokens[$role] ??= $this->postJson('/api/auth/login', [
            'identifier' => self::WHO[$role], 'password' => UserFactory::PASSWORD,
        ])->json('token');

        return $this->withToken($this->tokens[$role]);
    }

    private function sw(array $over = []): array
    {
        return [...self::SW, ...$over];
    }

    public function test_software_crud_and_permissions(): void
    {
        $this->as('student')->postJson('/api/software-resources', self::SW)->assertStatus(403);

        $r = $this->as('teacher')->postJson('/api/software-resources', $this->sw(['course_id' => $this->course->id]))
            ->assertStatus(201);
        $sid = $r->json('id');

        $this->assertSame('Visual Studio Code', $this->as('student')->getJson("/api/software-resources/$sid")->json('name'));

        $upd = $this->as('teacher')->putJson("/api/software-resources/$sid", ['category' => 'Editor'])->assertOk();
        $this->assertSame('Editor', $upd->json('category'));
        $this->assertSame('Visual Studio Code', $upd->json('name'));

        $this->as('student')->deleteJson("/api/software-resources/$sid")->assertStatus(403);
        $this->as('hod')->deleteJson("/api/software-resources/$sid")->assertStatus(204);
        $this->as('student')->getJson("/api/software-resources/$sid")->assertStatus(404);
    }

    public function test_software_validation_and_filters(): void
    {
        $this->as('teacher')->postJson('/api/software-resources', $this->sw(['download_url' => 'javascript:alert(1)']))->assertStatus(422);
        $this->as('teacher')->postJson('/api/software-resources', $this->sw(['download_url' => 'not a url']))->assertStatus(422);
        $this->as('teacher')->postJson('/api/software-resources', $this->sw(['course_id' => 999]))->assertStatus(404);

        $this->as('teacher')->postJson('/api/software-resources', $this->sw(['course_id' => $this->course->id]))->assertStatus(201);
        $this->as('teacher')->postJson('/api/software-resources', [
            'name' => 'MySQL Workbench', 'category' => 'Database',
            'download_url' => 'https://dev.mysql.com/downloads/workbench/',
        ])->assertStatus(201);

        $names = fn (array $p) => array_column(
            $this->as('student')->getJson('/api/software-resources?'.http_build_query($p))->assertOk()->json(), 'name'
        );

        $this->assertCount(2, $names([]));
        $this->assertSame(['MySQL Workbench'], $names(['category' => 'database']));
        $this->assertSame(['Visual Studio Code'], $names(['q' => 'visual']));
        $this->assertCount(1, $names(['course_id' => $this->course->id]));
    }

    public function test_software_needs_a_login(): void
    {
        $this->getJson('/api/software-resources')->assertStatus(401);
        $this->getJson('/api/software-resources/1')->assertStatus(401);
        $this->postJson('/api/software-resources', self::SW)->assertStatus(401);
    }

    public function test_missing_item_and_unknown_course_messages(): void
    {
        $this->as('student')->getJson('/api/software-resources/9999')
            ->assertStatus(404)->assertExactJson(['detail' => 'Software resource not found']);
        $this->as('teacher')->putJson('/api/software-resources/9999', ['name' => 'x'])->assertStatus(404);
        $this->as('teacher')->deleteJson('/api/software-resources/9999')->assertStatus(404);

        $this->as('teacher')->postJson('/api/software-resources', $this->sw(['course_id' => 999]))
            ->assertStatus(404)->assertExactJson(['detail' => 'Course not found']);
    }

    public function test_failed_update_changes_nothing(): void
    {
        $sid = $this->as('teacher')->postJson('/api/software-resources', self::SW)->json('id');

        $this->as('teacher')->putJson("/api/software-resources/$sid", ['name' => 'Renamed', 'course_id' => 999])->assertStatus(404);
        $this->as('teacher')->putJson("/api/software-resources/$sid", ['name' => 'Renamed', 'download_url' => 'ftp://x.org'])->assertStatus(422);

        $this->assertSame('Visual Studio Code', $this->as('student')->getJson("/api/software-resources/$sid")->json('name'));
    }

    public function test_update_cannot_set_required_fields_to_null(): void
    {
        $sid = $this->as('teacher')->postJson('/api/software-resources', self::SW)->json('id');

        foreach (['name', 'category', 'download_url'] as $field) {
            $this->as('teacher')->putJson("/api/software-resources/$sid", [$field => null])->assertStatus(422);
        }
        $this->as('teacher')->putJson("/api/software-resources/$sid", ['instructions' => null])
            ->assertOk()->assertJsonPath('instructions', null);
    }

    public function test_paging_limits(): void
    {
        $this->as('student')->getJson('/api/software-resources?limit=0')->assertStatus(422);
        $this->as('student')->getJson('/api/software-resources?limit=301')->assertStatus(422);
        $this->as('student')->getJson('/api/software-resources?skip=-1')->assertStatus(422);
        $this->as('student')->getJson('/api/software-resources?skip=0&limit=300')->assertOk();
    }
}