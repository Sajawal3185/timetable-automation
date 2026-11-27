<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** notifications table (backend/app/models/notification.py). */
class Notification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** The values of Python's NotificationType enum. */
    public const TYPES = ['schedule_changed', 'swap_requested', 'swap_approved', 'swap_rejected', 'makeup_added'];

    protected $fillable = ['user_id', 'type', 'message', 'related_id', 'is_read'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'related_id' => 'integer',
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
