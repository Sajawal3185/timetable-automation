<?php

namespace App\Http\Requests;

use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/makeup body (MakeupBookIn in schemas/makeup.py). Times may be sent
 * as "09:00" or "09:00:00", like Pydantic accepts; the date as "2026-12-01".
 */
class BookMakeupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'course_id' => ['required', new NotBlankInteger, 'integer'],
            'section_id' => ['required', new NotBlankInteger, 'integer'],
            'teacher_id' => ['required', new NotBlankInteger, 'integer'],
            'room_id' => ['required', new NotBlankInteger, 'integer'],
            'day_of_week' => ['required', 'string'],
            'start_time' => ['required', 'string', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'string', 'date_format:H:i,H:i:s'],
            'class_date' => ['nullable', 'string', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
            'term' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
