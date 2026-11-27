<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * timetable_entries table (backend/app/models/timetable_entry.py).
 * start_time / end_time stay strings ("09:00:00"); send them through Fmt::time().
 */
class TimetableEntry extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'term', 'section_id', 'course_id', 'teacher_id', 'room_id', 'day_of_week',
        'start_time', 'end_time', 'group_label', 'slot_index', 'block_id', 'elective_group',
    ];

    protected function casts(): array
    {
        return [
            'section_id' => 'integer',
            'course_id' => 'integer',
            'teacher_id' => 'integer',
            'room_id' => 'integer',
            'slot_index' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
