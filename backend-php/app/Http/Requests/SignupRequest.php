<?php

namespace App\Http\Requests;

use App\Rules\MaxBytes;
use App\Rules\NoNulByte;
use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/signup body (SignupRequest in #93's routers/signup.py).
 * A "role" in the body is not a rule here, so it is never read: the email decides.
 * The NUML-email shape, department, section and roll number are checked in the
 * controller, because Python answers them with its own messages (400 / 422).
 */
class SignupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:100'],
            'department_id' => ['required', new NotBlankInteger, 'integer'],
            'password' => ['required', 'string', 'min:8', new MaxBytes, new NoNulByte],
            'section_id' => ['nullable', new NotBlankInteger, 'integer'],   // students only
            'roll_number' => ['nullable', 'string', 'max:20'],             // students only
        ];
    }
}
