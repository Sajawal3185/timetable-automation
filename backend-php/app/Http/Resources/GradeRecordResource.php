<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** GradeRecordOut. VERIFY the field list against backend/app/schemas/portal.py. */
class GradeRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'term' => $this->term,
            'course_code' => $this->course_code,
            'course_title' => $this->course_title,
            'credit_hours' => (float) $this->credit_hours,   // decimal columns come back as strings
            'grade' => $this->grade,
        ];
    }
}
