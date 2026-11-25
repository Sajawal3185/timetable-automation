<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** teachers table (backend/app/models/teacher.py). */
class Teacher extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'normalized_name', 'department_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** The login account of this teacher; NULL until an admin links it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
