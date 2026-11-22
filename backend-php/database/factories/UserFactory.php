<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\Security;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test users. User::factory()->teacher()->create(), ->student(), ->hod(), ->admin(),
 * ->withPassword('x'), ->notActivated().
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'correct-horse-1';

    private static ?string $hash = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'identifier' => 'u-'.fake()->unique()->numerify('######'),
            'password_hash' => self::$hash ??= Security::hashPassword(self::PASSWORD),
            'role' => 'student',
            'is_active' => true,
            'token_version' => 0,
        ];
    }

    public function role(string $role): static
    {
        return $this->state(['role' => $role]);
    }

    public function student(): static
    {
        return $this->role('student');
    }

    public function teacher(): static
    {
        return $this->role('teacher');
    }

    public function hod(): static
    {
        return $this->role('hod');
    }

    public function admin(): static
    {
        return $this->role('admin');
    }

    public function withPassword(string $password): static
    {
        return $this->state(['password_hash' => Security::hashPassword($password)]);
    }

    /** A roster student who hasn't chosen a password yet. */
    public function notActivated(): static
    {
        return $this->state(['password_hash' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
