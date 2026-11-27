<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** events table (backend/app/models/event.py). */
class Event extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** The values of Python's EventType enum. */
    public const TYPES = ['deadline', 'event', 'holiday', 'department'];

    protected $fillable = [
        'title', 'event_type', 'description', 'start_date', 'end_date', 'location', 'department_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'department_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
