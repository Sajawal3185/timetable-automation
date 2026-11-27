<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** lecture_materials table (backend/app/models/lecture_material.py). */
class LectureMaterial extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'course_id', 'title', 'material_type', 'instructor_name', 'term', 'description',
        'file_path', 'original_filename', 'content_type', 'file_size', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'file_size' => 'integer',
            'uploaded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
