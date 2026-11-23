<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security;
use App\Support\Roles;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The 'jwt' and 'role' middleware (get_current_user and require_roles in Python).
 * Also covers test_role_checks.py: one shared denial message, role groups.
 */
class JwtAndRolesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/_test')->group(function () {
            Route::get('/me', fn (Request $r) => ['id' => $r->user()->id])->middleware('jwt');
            Route::get('/admins', fn () => ['ok' => true])->middleware('role:'.Roles::list(Roles::ADMINS));
            Route::get('/staff', fn () => ['ok' => true])->middleware(['jwt', 'role:teacher,hod,admin']);
            Route::get('/spaced', fn () => ['ok' => true])->middleware('role:hod, admin');
        });
    }

    /** Every 401 carries WWW-Authenticate: Bearer, whatever was wrong. */
    private function assertRefused(?string $token, string $detail = 'Invalid or expired token', string $why = ''): void
    {
        $request = $token === null ? $this->withoutToken() : $this->withToken($token);
        $response = $request->getJson('/api/_test/me');
        $this->assertSame(401, $response->status(), "not refused: $why");
        $response->assertExactJson(['detail' => $detail])
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_every_bad_token_is_401_with_bearer_challenge(): void
    {
        $user = User::factory()->create(['token_version' => 1]);
        $secret = config('timetable.jwt.secret');
        $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
        $good = Security::createAccessToken($user->id, 'student', 1);
        [$head, $body, $sig] = explode('.', $good);

        $bad = [
            'expired' => Security::createAccessToken($user->id, 'student', 1, expiresInMinutes: -1),
            'tampered payload' => $head.'.'.$b64(['sub' => '999', 'exp' => time() + 60, 'tv' => 1]).'.'.$sig,
            'wrong secret' => JWT::encode(['sub' => (string) $user->id, 'exp' => time() + 60, 'tv' => 1], str_repeat('w', 40), 'HS256'),
            'HS512' => JWT::encode(['sub' => (string) $user->id, 'exp' => time() + 60, 'tv' => 1], str_pad($secret, 64, 'x'), 'HS512'),
            'alg none' => $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$body.'.',
            'missing exp' => JWT::encode(['sub' => (string) $user->id, 'tv' => 1], $secret, 'HS256'),
            'missing sub' => JWT::encode(['exp' => time() + 60, 'tv' => 1], $secret, 'HS256'),
            'non-numeric sub' => JWT::encode(['sub' => 'abc', 'exp' => time() + 60, 'tv' => 1], $secret, 'HS256'),
            'old tv' => Security::createAccessToken($user->id, 'student', 0),
            'no tv, but token_version is 1' => JWT::encode(['sub' => (string) $user->id, 'exp' => time() + 60], $secret, 'HS256'),
        ];
        foreach ($bad as $why => $token) {
            $this->assertRefused($token, why: $why);
        }

        $this->assertRefused(null, 'Not authenticated');
        $user->update(['is_active' => false]);
        $this->assertRefused($good, 'User not found or inactive');
    }

    public function test_a_token_made_by_python_is_accepted(): void
    {
        // Signed by PyJWT with the phpunit.xml secret for user 1; it has no "tv",
        // which counts as 0 (every user's starting token_version).
        User::factory()->admin()->create(['id' => 1, 'token_version' => 0]);

        $this->withToken(\Tests\Unit\SecurityTest::PYTHON_TOKEN)->getJson('/api/_test/admins')->assertOk();
    }

    public function test_spaces_in_the_role_list_are_ignored(): void
    {
        // 'role:hod, admin' arrives as "hod" and " admin"; admins must still pass.
        $this->asRole('admin');
        $this->getJson('/api/_test/spaced')->assertOk();
    }

    public function test_role_is_compared_lowercase(): void
    {
        // The CHECK constraint keeps real rows lowercase; this is the belt to those braces.
        $this->assertSame('admin', Roles::of(new User(['role' => 'Admin'])));
    }

    public function test_role_groups(): void
    {
        $this->assertSame(['teacher', 'hod', 'admin'], Roles::STAFF);
        $this->assertSame(['hod', 'admin'], Roles::ADMINS);
    }

    public function test_valid_token_gives_the_user(): void
    {
        $user = $this->asRole('student');

        $this->getJson('/api/_test/me')->assertOk()->assertExactJson(['id' => $user->id]);
    }

    public function test_missing_token_is_401_with_bearer_challenge(): void
    {
        $this->getJson('/api/_test/me')->assertStatus(401)
            ->assertExactJson(['detail' => 'Not authenticated'])
            ->assertHeader('WWW-Authenticate', 'Bearer');

        $this->getJson('/api/_test/me', ['Authorization' => 'Basic abc'])->assertStatus(401);
    }

    public function test_bad_or_expired_token_is_401(): void
    {
        $user = User::factory()->create();
        $expired = Security::createAccessToken($user->id, 'student', 0, expiresInMinutes: -1);

        foreach (['garbage', $expired] as $token) {
            $this->withToken($token)->getJson('/api/_test/me')->assertStatus(401)
                ->assertExactJson(['detail' => 'Invalid or expired token']);
        }
    }

    public function test_deleted_or_inactive_user_is_401(): void
    {
        $inactive = User::factory()->inactive()->create();
        $this->asUser($inactive)->getJson('/api/_test/me')->assertStatus(401)
            ->assertExactJson(['detail' => 'User not found or inactive']);

        $gone = User::factory()->create();
        $token = $this->tokenFor($gone);
        $gone->delete();
        $this->withToken($token)->getJson('/api/_test/me')->assertStatus(401);
    }

    public function test_password_reset_logs_out_old_tokens(): void
    {
        $user = User::factory()->create();
        $old = $this->tokenFor($user);
        $user->update(['token_version' => 1]);   // what reset-password does

        $this->withToken($old)->getJson('/api/_test/me')->assertStatus(401);
        $this->withToken($this->tokenFor($user->fresh()))->getJson('/api/_test/me')->assertOk();
    }

    public function test_wrong_role_gets_the_shared_403(): void
    {
        $this->asRole('teacher');
        $this->getJson('/api/_test/admins')->assertStatus(403)->assertExactJson(['detail' => Roles::DENIED]);

        $this->asRole('student');
        $this->getJson('/api/_test/staff')->assertStatus(403)->assertExactJson(['detail' => Roles::DENIED]);
    }

    public function test_allowed_roles_pass(): void
    {
        $this->asRole('hod');
        $this->getJson('/api/_test/admins')->assertOk();
        $this->getJson('/api/_test/staff')->assertOk();
    }

    public function test_role_check_alone_still_requires_login(): void
    {
        $this->getJson('/api/_test/admins')->assertStatus(401);
    }

    public function test_role_comes_from_the_database_not_the_token(): void
    {
        $user = User::factory()->admin()->create();
        $token = $this->tokenFor($user);           // token says "admin"
        $user->update(['role' => 'student']);      // an admin demoted them since

        $this->withToken($token)->getJson('/api/_test/admins')->assertStatus(403);
    }
}
