<?php

namespace App\Http\Resources;

use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LectureMaterialOut in backend/app/schemas/portal.py: every field there, nothing more.
 * file_path is never exposed.
 */
class LectureMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'material_type' => $this->material_type,
            'instructor_name' => $this->instructor_name,
            'term' => $this->term,
            'description' => $this->description,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => Fmt::dateTime($this->created_at),
        ];
    }
}
