<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** sections table (backend/app/models/section.py). */
class Section extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'department_id', 'semester', 'student_count'];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'semester' => 'integer',
            'student_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
