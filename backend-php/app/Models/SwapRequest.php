<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** swap_requests table (backend/app/models/swap_request.py). */
class SwapRequest extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** The values of Python's SwapStatus enum. */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'requester_teacher_id', 'entry_id', 'target_entry_id', 'status', 'reason',
        'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'requester_teacher_id' => 'integer',
            'entry_id' => 'integer',
            'target_entry_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'requester_teacher_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class, 'entry_id');
    }

    public function targetEntry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class, 'target_entry_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
