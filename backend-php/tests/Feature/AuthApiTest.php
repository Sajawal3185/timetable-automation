<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Security;
use App\Support\Identifiers;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Port of backend/tests/test_auth.py (login, activate, /me, admin user creation),
 * plus the account part of test_hod_department.py. Real tokens through /login,
 * nothing faked.
 */
class AuthApiTest extends TestCase
{
    private const PASSWORD = UserFactory::PASSWORD;

    /** @var array<string, User> */
    private array $people;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        // One active account per role, plus a roster student with no password yet.
        $this->dept = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $section = Section::factory()->create(['name' => 'BSSE-5A', 'department_id' => $this->dept->id]);

        $make = fn (string $identifier, string $role, array $extra = []) => User::factory()
            ->role($role)->create(['identifier' => $identifier, 'name' => strtoupper($identifier), ...$extra]);

        $this->people = [
            'student' => $make('se-123456', 'student', ['section_id' => $section->id, 'department_id' => $this->dept->id]),
            'roster' => $make('se-654321', 'student', ['password_hash' => null, 'section_id' => $section->id]),
            'teacher' => $make('t-ali', 'teacher', ['department_id' => $this->dept->id]),
            'hod' => $make('h-sara', 'hod', ['department_id' => $this->dept->id]),
            'admin' => $make('a-root', 'admin'),
        ];
    }

    private function login(string $identifier, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/auth/login', ['identifier' => $identifier, 'password' => $password]);
    }

    /** Use a real token from /login for the next requests. */
    private function loginAs(string $identifier): static
    {
        return $this->withToken($this->login($identifier)->json('token'));
    }

    private function newStaff(array $overrides = []): array
    {
        return [...['name' => 'Dr. New', 'identifier' => 't-new', 'password' => 'long-enough-1', 'role' => 'teacher'], ...$overrides];
    }

    // ------------------------------------------------------------------ login

    public function test_login_success_returns_token_and_user(): void
    {
        $response = $this->login('se-123456')->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('se-123456', $response->json('user.identifier'));
        $this->assertSame('student', $response->json('user.role'));
        $this->assertSame(['token', 'user'], array_keys($response->json()));
        $this->assertSame(
            ['id', 'name', 'role', 'identifier', 'department_id', 'section_id', 'teacher_id'],
            array_keys($response->json('user')),
        );
    }

    public function test_login_wrong_password_and_unknown_identifier_are_both_401(): void
    {
        $wrong = $this->login('se-123456', 'not-the-password')->assertStatus(401);
        $unknown = $this->login('se-000000')->assertStatus(401);

        // Same message, so login can't be used to find out which roll numbers exist.
        $this->assertSame('Invalid credentials', $wrong->json('detail'));
        $this->assertSame($wrong->json('detail'), $unknown->json('detail'));
    }

    public function test_login_for_unactivated_student_is_401(): void
    {
        $this->login('se-654321', '')->assertStatus(422);   // empty password isn't even accepted
        $this->login('se-654321', 'anything-at-all')->assertStatus(401);
    }

    public function test_login_for_disabled_account_is_401(): void
    {
        $this->people['teacher']->update(['is_active' => false]);

        $this->login('t-ali')->assertStatus(401)->assertExactJson(['detail' => 'Invalid credentials']);
    }

    public function test_login_identifier_ignores_case_and_spaces(): void
    {
        $this->login(' SE-123456 ')->assertOk()->assertJsonPath('user.identifier', 'se-123456');
    }

    public function test_login_with_the_email_too(): void
    {
        // From test_signup.py: signed-up accounts log in with the ID or the full email.
        User::factory()->create(['identifier' => 'numl-f24-26789', 'email' => 'numl-f24-26789@numls.edu.pk']);

        $this->login('numl-f24-26789')->assertOk();
        $this->login('  NUML-F24-26789@numls.edu.pk ')->assertOk();
        $this->login('numl-f24-26789@numls.edu.pk', 'wrong-password')->assertStatus(401)
            ->assertExactJson(['detail' => 'Invalid credentials']);
    }

    public function test_passwords_are_not_trimmed(): void
    {
        // Laravel trims every input except fields named "password" unless that's
        // turned off (bootstrap/app.php); new_password would lose its spaces.
        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => '  spaced out  '])
            ->assertOk();

        $this->login('se-654321', '  spaced out  ')->assertOk();
        $this->login('se-654321', 'spaced out')->assertStatus(401);
    }

    public function test_trailing_space_password_through_activate_and_login(): void
    {
        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => 'ends-in-space '])
            ->assertOk();

        $this->login('se-654321', 'ends-in-space ')->assertOk();
        $this->login('se-654321', 'ends-in-space')->assertStatus(401);
        $this->login('se-654321', 'ends-in-space  ')->assertStatus(401);

        // Same for an account an admin made (password set at creation, not activation).
        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['password' => 'staff-pass-1 ']))
            ->assertStatus(201);
        $this->login('t-new', 'staff-pass-1 ')->assertOk();
        $this->login('t-new', 'staff-pass-1')->assertStatus(401);
    }

    // ------------------------------------------------------------------ activate

    public function test_activate_logs_the_student_in_and_works_only_once(): void
    {
        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => 'my-new-pass'])
            ->assertOk()->assertJsonPath('user.identifier', 'se-654321');
        $this->login('se-654321', 'my-new-pass')->assertOk();

        // Now it has a password, so it can't be claimed again (that would let anyone
        // who knows a roll number take over the account).
        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => 'hijack-pass'])
            ->assertStatus(400)->assertExactJson(['detail' => 'This account cannot be activated']);
        $this->login('se-654321', 'hijack-pass')->assertStatus(401);
    }

    public function test_activate_on_a_teacher_account_is_rejected(): void
    {
        $this->postJson('/api/auth/activate', ['identifier' => 't-ali', 'new_password' => 'takeover-pass'])
            ->assertStatus(400);
    }

    public function test_activate_checks_the_new_password(): void
    {
        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => 'short'])->assertStatus(422);
        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => str_repeat('é', 37)])
            ->assertStatus(422)->assertJsonPath('detail.0.msg', 'Password is too long (max 72 bytes)');
    }

    // ------------------------------------------------------------------ POST /users

    public function test_only_admin_can_create_users(): void
    {
        foreach (['se-123456', 't-ali', 'h-sara'] as $identifier) {
            $this->loginAs($identifier)->postJson('/api/auth/users', $this->newStaff())->assertStatus(403);
        }

        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['identifier' => ' T-NEW ']))
            ->assertStatus(201)
            ->assertJsonPath('identifier', 't-new')
            ->assertJsonPath('role', 'teacher');
        $this->login('t-new', 'long-enough-1')->assertOk();
    }

    public function test_create_user_without_a_token_is_401(): void
    {
        $this->postJson('/api/auth/users', $this->newStaff())->assertStatus(401);
    }

    public function test_admin_cannot_create_a_student(): void
    {
        // Students come from the roster import, never from the admin form.
        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['role' => 'student']))
            ->assertStatus(400)->assertExactJson(['detail' => 'role must be one of: admin, hod, teacher']);
    }

    public function test_duplicate_identifier_is_409(): void
    {
        $this->loginAs('a-root');
        $this->postJson('/api/auth/users', $this->newStaff(['identifier' => 't-ali']))
            ->assertStatus(409)->assertExactJson(['detail' => 'Identifier already exists']);
        // Case and spaces are normalised first, so this is the same identifier.
        $this->postJson('/api/auth/users', $this->newStaff(['identifier' => ' T-Ali ']))->assertStatus(409);
    }

    // From test_hod_department.py: a HOD needs a department.

    public function test_creating_hod_without_department_gives_400(): void
    {
        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['role' => 'hod']))
            ->assertStatus(400)->assertExactJson(['detail' => 'department_id is required when role is hod']);
    }

    public function test_creating_hod_with_department_saves_it(): void
    {
        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['role' => 'hod', 'department_id' => $this->dept->id]))
            ->assertStatus(201)
            ->assertJsonPath('role', 'hod')
            ->assertJsonPath('department_id', $this->dept->id);
    }

    public function test_creating_hod_with_unknown_department_gives_400(): void
    {
        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['role' => 'hod', 'department_id' => 9999]))
            ->assertStatus(400)->assertExactJson(['detail' => 'Department not found']);
    }

    public function test_teacher_account_does_not_need_a_department(): void
    {
        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff())->assertStatus(201)
            ->assertJsonPath('department_id', null);
    }

    public function test_new_user_password_rules(): void
    {
        $this->loginAs('a-root');
        $this->postJson('/api/auth/users', $this->newStaff(['password' => 'short']))->assertStatus(422);
        $this->postJson('/api/auth/users', $this->newStaff(['password' => str_repeat('a', 73)]))->assertStatus(422);
    }

    // ------------------------------------------------------------------ /me

    public function test_me_returns_the_new_fields(): void
    {
        $student = $this->people['student'];
        $this->loginAs('se-123456')->getJson('/api/auth/me')->assertOk()->assertExactJson([
            'id' => $student->id, 'name' => 'SE-123456', 'role' => 'student', 'identifier' => 'se-123456',
            'department_id' => $student->department_id, 'section_id' => $student->section_id, 'teacher_id' => null,
        ]);

        $hod = $this->loginAs('h-sara')->getJson('/api/auth/me');
        $hod->assertJsonPath('department_id', $this->people['hod']->department_id)->assertJsonPath('section_id', null);
    }

    public function test_me_and_login_return_the_linked_teacher_id(): void
    {
        $row = Teacher::factory()->create([
            'name' => 'Dr. Ali', 'user_id' => $this->people['teacher']->id, 'department_id' => $this->dept->id,
        ]);

        $this->loginAs('t-ali')->getJson('/api/auth/me')->assertJsonPath('teacher_id', $row->id);
        $this->login('t-ali')->assertJsonPath('user.teacher_id', $row->id);

        // no Teacher row -> null, never someone else's id
        $this->loginAs('se-123456')->getJson('/api/auth/me')->assertJsonPath('teacher_id', null);
        $this->loginAs('h-sara')->getJson('/api/auth/me')->assertJsonPath('teacher_id', null);
    }

    public function test_me_without_a_token_is_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_user_json_never_contains_the_password_hash(): void
    {
        $body = $this->login('se-123456')->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    // ------------------------------------------------------------------ removed endpoint

    public function test_register_endpoint_is_gone(): void
    {
        $status = $this->postJson('/api/auth/register', [
            'name' => 'Anyone', 'identifier' => 'se-999999', 'section_id' => 1, 'password' => 'long-enough-1',
        ])->status();

        $this->assertContains($status, [404, 405]);
    }

    // ------------------------------------------------------------------ races (review on #98)

    public function test_two_activations_at_once_only_one_wins(): void
    {
        // Simulate a second request that activates the account between our
        // "has no password yet" check and our UPDATE.
        User::retrieved(function (User $user) {
            if ($user->identifier === 'se-654321' && $user->password_hash === null) {
                DB::table('users')->where('id', $user->id)->update(['password_hash' => Security::hashPassword('first-wins')]);
            }
        });

        $this->postJson('/api/auth/activate', ['identifier' => 'se-654321', 'new_password' => 'second-loses'])
            ->assertStatus(400)->assertExactJson(['detail' => 'This account cannot be activated']);

        $this->login('se-654321', 'first-wins')->assertOk();
        $this->login('se-654321', 'second-loses')->assertStatus(401);
    }

    public function test_two_creates_of_the_same_identifier_at_once_give_409(): void
    {
        // Simulate a second request that inserts the same identifier after our
        // exists() check passed: the UNIQUE index must turn it into a 409, not a 500.
        User::creating(function (User $user) {
            if ($user->identifier === 't-race' && ! DB::table('users')->where('identifier', 't-race')->exists()) {
                DB::table('users')->insert(['name' => 'Other', 'identifier' => 't-race', 'role' => 'teacher']);
            }
        });

        $this->loginAs('a-root')->postJson('/api/auth/users', $this->newStaff(['identifier' => 'T-Race']))
            ->assertStatus(409)->assertExactJson(['detail' => 'Identifier already exists']);
        $this->assertSame(1, User::where('identifier', 't-race')->count());
    }

    // ------------------------------------------------------------------ rate limit (review on #98)

    public function test_login_and_activate_are_rate_limited_per_account(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->login('se-123456', 'wrong-guess-'.$i)->assertStatus(401);
        }
        $this->login('se-123456')->assertStatus(429)
            ->assertExactJson(['detail' => 'Too Many Attempts.'])
            ->assertHeader('Retry-After');

        // Another account from the same address is not affected.
        $this->login('t-ali')->assertOk();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/activate', ['identifier' => 't-ali', 'new_password' => 'guess-'.$i.'-xx']);
        }
        $this->postJson('/api/auth/activate', ['identifier' => 'T-ALI ', 'new_password' => 'guess-xx-xx'])
            ->assertStatus(429);   // same account after normalising
    }

    public function test_changing_address_does_not_escape_the_account_limit(): void
    {
        // Each try "from" a different address (what a faked X-Forwarded-For does).
        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                ->login('se-123456', 'wrong-guess-'.$i)->assertStatus(401);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])->login('se-123456')->assertStatus(429);
    }

    public function test_rate_limit_answer_is_429_detail_with_retry_after(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->login('se-123456', 'wrong-guess-'.$i);
        }
        $response = $this->login('se-123456')->assertStatus(429)
            ->assertExactJson(['detail' => 'Too Many Attempts.']);

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $retryAfter);
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);
    }

    public function test_login_and_activate_share_one_budget(): void
    {
        // 6 logins + 4 activations for the same account = the 10 a minute.
        for ($i = 0; $i < 6; $i++) {
            $this->login('se-123456', 'wrong-guess-'.$i)->assertStatus(401);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/activate', ['identifier' => 'se-123456', 'new_password' => 'guess-'.$i.'-xx'])
                ->assertStatus(400);   // already activated
        }
        $this->login('se-123456')->assertStatus(429);
        $this->postJson('/api/auth/activate', ['identifier' => 'se-123456', 'new_password' => 'guess-xx-xx'])
            ->assertStatus(429);
    }

    public function test_rate_limit_address_is_the_one_render_appended(): void
    {
        // Render's proxy (REMOTE_ADDR) appends the caller's real address LAST.
        // The client controls everything before it, so a new fake first entry
        // per try must not give a new budget.
        $viaRender = fn (string $forwardedFor) => $this
            ->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
            ->withHeaders(['X-Forwarded-For' => $forwardedFor]);

        for ($i = 0; $i < 10; $i++) {
            $viaRender("6.6.6.$i, 198.51.100.7")->login('se-123456', 'wrong-guess-'.$i)->assertStatus(401);
        }
        $viaRender('6.6.6.99, 198.51.100.7')->login('se-123456')->assertStatus(429);

        // A different real address is a different per-address budget (the
        // per-account 30 is not reached yet), so the proxy's own IP is not the key.
        $viaRender('198.51.100.8')->login('se-123456')->assertOk();

        $this->assertSame('198.51.100.7', $viaRender('6.6.6.6, 198.51.100.7')
            ->get('/api/health')->baseRequest->ip());
    }

    public function test_requests_without_a_usable_identifier_are_422_not_429(): void
    {
        // No account to protect, so only the per-address limit applies: 35 bad
        // requests from different addresses never share one empty-account bucket.
        foreach ([[], ['identifier' => ['x']], ['identifier' => '   ']] as $body) {
            for ($i = 0; $i < 12; $i++) {
                $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                    ->postJson('/api/auth/login', [...$body, 'password' => 'whatever-1'])
                    ->assertStatus(422);
            }
        }
    }

    // ------------------------------------------------------------------ identifiers (review on #98)

    public function test_identifier_is_trimmed_like_python_strip(): void
    {
        // Python's str.strip() also removes a no-break space and an ideographic
        // space (pasted from a PDF roster); PHP's trim() wouldn't.
        $padded = mb_chr(0xA0).'SE-123456'.mb_chr(0x3000)."\t";
        $this->assertSame('se-123456', Identifiers::normalize($padded));
        $this->login($padded)->assertOk();

        // A no-break space in the MIDDLE is part of the identifier, as in Python.
        $this->assertSame('se'.mb_chr(0xA0).'1', Identifiers::normalize(' SE'.mb_chr(0xA0).'1 '));
    }

    // ------------------------------------------------------------------ docs page

    public function test_docs_lock_only_the_endpoints_that_need_a_token(): void
    {
        $paths = $this->getJson('/openapi.json')->assertOk()->json('paths');

        foreach (['/auth/login' => 'post', '/auth/activate' => 'post', '/health' => 'get'] as $path => $method) {
            $this->assertSame([], $paths[$path][$method]['security'] ?? null, "$path should not need a token");
        }
        foreach (['/auth/me' => 'get', '/auth/users' => 'post'] as $path => $method) {
            $this->assertArrayNotHasKey('security', $paths[$path][$method], "$path should need a token");
        }
    }
}
