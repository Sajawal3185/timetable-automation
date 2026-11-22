<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/auth/login body (LoginRequest in auth.py). */
class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // roll number / staff id / numl-f24-26789, or the NUML email
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
