<?php

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CourseOut (schemas/portal.py).
 *
 * @mixin Course
 */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'credit_hours' => $this->credit_hours,
            'department_id' => $this->department_id,
        ];
    }
}
