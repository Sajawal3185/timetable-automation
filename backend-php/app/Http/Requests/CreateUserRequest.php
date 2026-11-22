<?php

namespace App\Http\Requests;

use App\Rules\MaxBytes;
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
            'name' => ['required', 'string'],
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', new MaxBytes],
            'role' => ['required', 'string'],
            'department_id' => ['nullable', 'integer'],
        ];
    }
}
