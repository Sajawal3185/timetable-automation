<?php

namespace App\Http\Requests;

use App\Rules\MaxBytes;
use App\Rules\NoNulByte;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/auth/activate body (ActivateRequest in auth.py). */
class ActivateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            // 8 is a floor, not a policy; raise it if the team wants
            'new_password' => ['required', 'string', 'min:8', new MaxBytes, new NoNulByte],
        ];
    }
}
