<?php

namespace App\Http\Requests;

use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/admin/users/{id} body: any of {role, teacher_id, department_id}.
 * Only the fields sent change; teacher_id or department_id null clears them.
 * Which changes are allowed is checked in the controller (400/409 with a message).
 */
class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'required', 'string'],
            'teacher_id' => ['sometimes', 'nullable', new NotBlankInteger, 'integer'],
            'department_id' => ['sometimes', 'nullable', new NotBlankInteger, 'integer'],
        ];
    }
}
