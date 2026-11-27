<?php

namespace App\Http\Resources;

use App\Models\TimetableEntry;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TimetableEntryOut (schemas/portal.py). Only ids: the frontend looks the
 * names up in /api/courses, /api/rooms and so on.
 *
 * @mixin TimetableEntry
 */
class TimetableEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'course_id' => $this->course_id,
            'teacher_id' => $this->teacher_id,
            'room_id' => $this->room_id,
            'day_of_week' => $this->day_of_week,
            'start_time' => Fmt::time($this->start_time),
            'end_time' => Fmt::time($this->end_time),
        ];
    }
}
