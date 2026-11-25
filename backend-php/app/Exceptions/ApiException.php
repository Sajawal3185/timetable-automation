<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error whose "detail" is not a plain string. For a string, use
 * abort(404, 'Room not found') instead.
 *
 * Example, the swap clash (409):
 *   throw new ApiException(409, ['message' => 'This swap clashes', 'conflicts' => $conflicts]);
 * becomes {"detail": {"message": "...", "conflicts": [...]}}
 */
class ApiException extends RuntimeException
{
    /** @param  string|array<mixed>  $detail */
    public function __construct(public readonly int $status, public readonly string|array $detail, public readonly array $headers = [])
    {
        parent::__construct(is_string($detail) ? $detail : (string) ($detail['message'] ?? 'API error'));
    }
}
