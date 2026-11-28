<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** SoftwareOut (backend/app/schemas/portal.py). Named Item to avoid clashing with the model. */
class SoftwareItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'download_url' => $this->download_url,
            'instructions' => $this->instructions,
            'course_id' => $this->course_id,
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s'),
        ];
    }
}
