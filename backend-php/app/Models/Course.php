<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** courses table (backend/app/models/course.py). */
class Course extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['code', 'title', 'credit_hours', 'is_lab', 'department_id'];

    protected function casts(): array
    {
        return [
            'credit_hours' => 'integer',
            'is_lab' => 'boolean',
            'department_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
