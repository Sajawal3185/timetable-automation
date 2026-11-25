<?php

namespace App\Http\Requests;

use App\Rules\MaxBytes;
use App\Rules\NoNulByte;
use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/users body (CreateUserRequest in auth.py).
 * Which roles are allowed, and the HOD/department rules, are checked in the
 * controller, because Python answers those with 400, not 422.
 */
class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Column sizes (users.name VARCHAR(100), users.identifier VARCHAR(150)):
            // strict MySQL refuses longer values with a 500. Python has no cap.
            'name' => ['required', 'string', 'max:100'],
            'identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:8', new MaxBytes, new NoNulByte],
            'role' => ['required', 'string'],
            'department_id' => ['nullable', new NotBlankInteger, 'integer'],
        ];
    }
}
