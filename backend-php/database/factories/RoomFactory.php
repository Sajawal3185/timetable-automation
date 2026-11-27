<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Room> */
class RoomFactory extends Factory
{
    /** A counter, not fake()->unique(): rooms.name is UNIQUE (see SectionFactory). */
    private static int $next = 0;

    public function definition(): array
    {
        return [
            'name' => 'R-'.++self::$next,
            'capacity' => 50,
            'room_type' => 'lecture',
        ];
    }

    public function lab(): static
    {
        return $this->state(['room_type' => 'lab']);
    }
}
