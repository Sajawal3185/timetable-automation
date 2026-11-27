<?php

namespace App\Http\Requests;

use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/makeup/find-slots body (MakeupSearchIn in schemas/makeup.py). */
class FindMakeupSlotsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'section_id' => ['required', new NotBlankInteger, 'integer'],
            'teacher_id' => ['required', new NotBlankInteger, 'integer'],
            'course_id' => ['required', new NotBlankInteger, 'integer'],
            'duration_minutes' => ['sometimes', new NotBlankInteger, 'integer', 'min:30', 'max:240'],
            'preferred_room_id' => ['nullable', new NotBlankInteger, 'integer'],
            'days' => ['nullable', 'array'],
            'days.*' => ['string'],
            'term' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
