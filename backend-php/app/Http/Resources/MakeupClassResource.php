<?php

namespace App\Http\Resources;

use App\Models\MakeupClass;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MakeupClassOut (schemas/makeup.py).
 *
 * @mixin MakeupClass
 */
class MakeupClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'section_id' => $this->section_id,
            'teacher_id' => $this->teacher_id,
            'room_id' => $this->room_id,
            'class_date' => Fmt::date($this->class_date),
            'day_of_week' => $this->day_of_week,
            'start_time' => Fmt::time($this->start_time),
            'end_time' => Fmt::time($this->end_time),
            'reason' => $this->reason,
            'status' => $this->status,
        ];
    }
}
