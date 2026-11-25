<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * For optional integer fields ("department_id": null is fine, "" is not).
 *
 * Laravel skips every non-implicit rule (integer included) when the value is
 * "" or only spaces, and ConvertEmptyStringsToNull is off (bootstrap/app.php),
 * so "" would pass validation. Pydantic can't parse "" as an int and gives 422,
 * so this rule is implicit: it runs even for "", and fails it.
 */
class NotBlankInteger implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) === '') {
            $fail('Input should be a valid integer, unable to parse string as an integer');
        }
    }
}
