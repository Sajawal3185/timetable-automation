<?php

namespace App\Http\Requests;

use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/rooms body (RoomIn in schemas/portal.py). */
class StoreRoomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'capacity' => ['required', new NotBlankInteger, 'integer', 'min:1', 'max:1000'],
            'building' => ['nullable', 'string', 'max:50'],
        ];
    }
}
