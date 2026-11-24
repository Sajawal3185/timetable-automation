<?php

namespace Database\Factories;

use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Teacher> */
class TeacherFactory extends Factory
{
    public function definition(): array
    {
        // No ->unique(): it runs out of last names over a long test run, and
        // teachers.name has no unique key (normalized_name is left null here).
        return ['name' => 'Dr. '.fake()->lastName()];
    }
}
