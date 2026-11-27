<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * makeup_classes table (backend/app/models/makeup_class.py).
 * start_time / end_time stay strings ("09:00:00"); send them through Fmt::time().
 * active_slot is computed by the database: it is not fillable, never set it.
 */
class MakeupClass extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'course_id', 'section_id', 'teacher_id', 'room_id', 'class_date', 'day_of_week',
        'start_time', 'end_time', 'reason', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'section_id' => 'integer',
            'teacher_id' => 'integer',
            'room_id' => 'integer',
            'class_date' => 'date',
            'active_slot' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
