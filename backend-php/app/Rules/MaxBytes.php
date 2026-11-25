<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passwords: bcrypt only reads the first 72 BYTES, so longer ones are refused.
 * Counts bytes, not characters ("é" is 2 bytes). From _check_password_length in auth.py.
 */
class MaxBytes implements ValidationRule
{
    public function __construct(private int $max = 72) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > $this->max) {
            $fail("Password is too long (max {$this->max} bytes)");
        }
    }
}
