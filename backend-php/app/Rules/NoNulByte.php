<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passwords: bcrypt stops reading at a NUL byte, so Security::hashPassword()
 * refuses one. Refusing it here makes that a 422 instead of a 500.
 */
class NoNulByte implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('Password cannot contain a NUL character');
        }
    }
}
