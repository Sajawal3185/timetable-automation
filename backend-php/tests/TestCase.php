<?php

namespace Tests;

use App\Models\User;
use App\Services\Security;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base class for every test that needs the app or the database.
 * PHP twin of backend/tests/conftest.py.
 *
 * - Each test gets a fresh in-memory SQLite database (RefreshDatabase).
 * - asRole('teacher') makes a user with that role and sends the following
 *   requests with a REAL token for them, so the jwt and role checks run
 *   exactly as in production (no faking of the logged-in user).
 *
 *   $teacher = $this->asRole('teacher');
 *   $this->postJson('/api/rooms', [...])->assertStatus(403)
 *        ->assertJson(['detail' => Roles::DENIED]);
 *
 * Pure-logic tests that don't need Laravel can extend PHPUnit\Framework\TestCase.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Create a user with this role (plus any columns) and log in as them. */
    protected function asRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->role($role)->create($attributes);
        $this->asUser($user);

        return $user;
    }

    /** Send the following requests as this user, with a real token. */
    protected function asUser(User $user): static
    {
        return $this->withToken($this->tokenFor($user));
    }

    protected function tokenFor(User $user): string
    {
        return Security::createAccessToken($user->id, $user->role, $user->token_version);
    }
}
