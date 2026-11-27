<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** grade_records table (backend/app/models/grade_record.py). */
class GradeRecord extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'term', 'course_code', 'course_title', 'credit_hours', 'grade'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'credit_hours' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
