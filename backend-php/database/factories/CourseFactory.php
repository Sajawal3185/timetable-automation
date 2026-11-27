<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    /** A counter, not fake()->unique(): (code, department_id) is UNIQUE (see SectionFactory). */
    private static int $next = 0;

    public function definition(): array
    {
        $n = ++self::$next;

        return [
            'code' => 'CS-'.$n,
            'title' => 'Course '.$n,
            'credit_hours' => 3,
            'is_lab' => false,
            'department_id' => Department::factory(),
        ];
    }
}
