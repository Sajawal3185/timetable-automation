<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Section> */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'BSSE-'.fake()->unique()->numerify('#').fake()->randomLetter(),
            'department_id' => Department::factory(),
            'student_count' => 40,
        ];
    }
}
