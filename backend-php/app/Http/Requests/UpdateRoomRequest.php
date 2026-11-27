<?php

namespace App\Http\Requests;

use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/rooms/{id} body (RoomUpdate in schemas/portal.py). Only the fields
 * sent are changed. "capacity": null sets it back to unknown. "name": null is
 * refused with 422 (in Python it reaches the NOT NULL column and gives a 500).
 */
class UpdateRoomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:50'],
            'capacity' => ['sometimes', 'nullable', new NotBlankInteger, 'integer', 'min:1', 'max:1000'],
            'building' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
