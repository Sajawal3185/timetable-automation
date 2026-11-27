<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** software_resources table (backend/app/models/software_resource.py). */
class SoftwareResource extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'category', 'description', 'download_url', 'instructions', 'course_id'];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
