<?php

namespace App\Http\Resources;

use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ExamPaperOut. TODO: compare the field list with ExamPaperOut in backend/app/schemas/portal.py
 * and docs/api-contract.md: every field there, nothing more. file_path is never exposed.
 */
class ExamPaperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'term' => $this->term,
            'exam_type' => $this->exam_type,
            'year' => $this->year,
            'instructor_name' => $this->instructor_name,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => Fmt::dateTime($this->created_at),
        ];
    }
}
