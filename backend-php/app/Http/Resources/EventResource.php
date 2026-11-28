<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** EventOut (backend/app/schemas/portal.py): same fields, same order. */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'event_type' => $this->event_type,
            'description' => $this->description,
            'start_date' => $this->start_date?->toDateString(),   // "2026-10-20"
            'end_date' => $this->end_date?->toDateString(),
            'location' => $this->location,
            'department_id' => $this->department_id,
        ];
    }
}
