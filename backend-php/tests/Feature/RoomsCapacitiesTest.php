<?php

namespace Tests\Feature;

use App\Models\Room;
use Tests\TestCase;

class RoomsCapacitiesTest extends TestCase
{
    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rooms');
        file_put_contents($path, $body);

        return $path;
    }

    private function room(string $name): Room
    {
        return Room::factory()->create(['name' => $name, 'capacity' => null]);
    }

    public function test_matching_rooms_get_their_capacity(): void
    {
        $room = $this->room('Room # 8 - Ghazali');

        $this->artisan('rooms:capacities', ['file' => $this->csv("name,capacity\n  room # 8 - GHAZALI ,45\n")])
            ->assertExitCode(0);

        $this->assertSame(45, $room->fresh()->capacity);
    }

    public function test_rows_that_match_no_room_are_listed(): void
    {
        $this->room('Room 1');

        $this->artisan('rooms:capacities', ['file' => $this->csv("name,capacity\nNo Such Room,30\n")])
            ->expectsOutputToContain('No room matches: No Such Room')
            ->assertExitCode(0);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $room = $this->room('Room 1');

        $this->artisan('rooms:capacities', ['file' => $this->csv("name,capacity\nRoom 1,40\n"), '--dry-run' => true])
            ->expectsOutputToContain('Would update 1')
            ->assertExitCode(0);

        $this->assertNull($room->fresh()->capacity);
    }

    public function test_bad_capacities_are_refused(): void
    {
        $a = $this->room('A');
        $b = $this->room('B');
        $c = $this->room('C');
        $d = $this->room('D');
        $ok = $this->room('Ok');

        $this->artisan('rooms:capacities', ['file' => $this->csv("name,capacity\nA,0\nB,-5\nC,abc\nD,12.5\nOk,30\n")])
            ->assertExitCode(1);

        foreach ([$a, $b, $c, $d] as $room) {
            $this->assertNull($room->fresh()->capacity);
        }
        $this->assertSame(30, $ok->fresh()->capacity);
    }

    public function test_blank_capacity_is_skipped_not_refused(): void
    {
        $room = $this->room('Room 1');

        $this->artisan('rooms:capacities', ['file' => $this->csv("name,capacity\nRoom 1,\n")])
            ->assertExitCode(0);

        $this->assertNull($room->fresh()->capacity);
    }
}