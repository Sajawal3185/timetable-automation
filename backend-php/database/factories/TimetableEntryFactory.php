<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimetableEntry> */
class TimetableEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'term' => 'Fall 2026',
            'section_id' => Section::factory(),
            'course_id' => Course::factory(),
            'teacher_id' => Teacher::factory(),
            'room_id' => Room::factory(),
            'day_of_week' => 'Monday',
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
            'group_label' => '',
        ];
    }
}
