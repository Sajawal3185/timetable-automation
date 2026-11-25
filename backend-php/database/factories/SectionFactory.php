<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Section> */
class SectionFactory extends Factory
{
    /**
     * Counts up for the whole test run. Not fake()->unique(): its tracker also
     * lasts the whole run, and numerify('#') has only 10 values, so the 11th
     * section threw OverflowException. A plain random name could repeat inside
     * one department (unique key uq_section_name_dept), which would be a flaky test.
     */
    private static int $next = 0;

    public function definition(): array
    {
        return [
            'name' => 'BSSE-'.++self::$next,
            'department_id' => Department::factory(),
            'student_count' => 40,
        ];
    }
}
