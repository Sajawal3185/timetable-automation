<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MakeupClass;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The 15 tables of the Alembic migration, and the keys the services rely on. */
class SchemaTest extends TestCase
{
    public function test_every_alembic_table_exists(): void
    {
        $tables = [
            'departments', 'rooms', 'courses', 'sections', 'software_resources', 'users', 'events',
            'exam_papers', 'grade_records', 'lecture_materials', 'notifications', 'teachers',
            'makeup_classes', 'timetable_entries', 'swap_requests',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table $table");
        }
    }

    public function test_a_room_slot_takes_one_scheduled_makeup_only(): void
    {
        $room = Room::factory()->create();
        $this->makeup($room);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeup($room);
    }

    public function test_a_cancelled_makeup_frees_its_room_slot(): void
    {
        $room = Room::factory()->create();
        $first = $this->makeup($room);
        $this->assertSame(1, $first->fresh()->active_slot);

        $first->update(['status' => 'cancelled']);
        $this->assertNull($first->fresh()->active_slot);

        $this->makeup($room);
        $this->assertSame(2, MakeupClass::count());
    }

    public function test_the_same_class_twice_in_one_slot_is_refused(): void
    {
        $entry = TimetableEntry::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        TimetableEntry::create($entry->only([
            'term', 'section_id', 'course_id', 'day_of_week', 'start_time', 'end_time', 'group_label',
        ]));
    }

    /** A scheduled makeup in $room, Tuesday 11:00, with its own course, section and teacher. */
    private function makeup(Room $room): MakeupClass
    {
        return MakeupClass::create([
            'course_id' => Course::factory()->create()->id,
            'section_id' => Section::factory()->create()->id,
            'teacher_id' => Teacher::factory()->create()->id,
            'room_id' => $room->id,
            'day_of_week' => 'Tuesday',
            'start_time' => '11:00:00',
            'end_time' => '12:30:00',
        ]);
    }
}
