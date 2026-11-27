<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\EmailCode;
use Illuminate\Support\Carbon;

/**
 * 6-digit email codes for signup and forgot password (#93's services/email_codes.py).
 *
 * 6 digits, valid 10 minutes, single use, 5 wrong tries, at most one new code a
 * minute per (email, purpose). Only an HMAC of the code is stored, keyed with
 * the JWT secret, so someone reading the database still can't use a code.
 */
final class EmailCodes
{
    public const CODE_LENGTH = 6;

    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const WRONG_CODE = 'The code is wrong or has expired. Ask for a new one.';

    public const TOO_MANY = 'Too many attempts. Try again later.';

    public const WAIT_A_MINUTE = 'Please wait a minute before asking for a new code.';

    /** True if a code for this email and purpose went out less than a minute ago. */
    public static function sentRecently(string $email, string $purpose): bool
    {
        $row = self::row($email, $purpose);

        return $row !== null && $row->sent_at->gt(self::now()->subSeconds(self::RESEND_SECONDS));
    }

    /**
     * Creates (or replaces) the code for this email and purpose and returns it;
     * the caller emails it. Replacing resets the attempt count: old guesses were
     * against a different number. Call it inside the caller's transaction.
     */
    public static function issue(string $email, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $now = self::now();
        EmailCode::updateOrCreate(['email' => $email, 'purpose' => $purpose], [
            'code_hash' => self::hash($email, $purpose, $code),
            'expires_at' => $now->copy()->addMinutes(self::CODE_MINUTES),
            'attempts' => 0,
            'sent_at' => $now,
        ]);

        return $code;
    }

    /**
     * Checks the code. A wrong guess is saved at once, outside any transaction,
     * because the request then fails and a rollback would undo it: the 5-try
     * limit could never trigger. On success it returns the row; the caller
     * deletes it (single use) in the same transaction as what the code unlocks.
     *
     * @throws ApiException 400 wrong or expired, 429 too many tries
     */
    public static function check(string $email, string $purpose, string $code): EmailCode
    {
        $row = self::row($email, $purpose);
        if ($row === null || $row->expires_at->lt(self::now())) {
            throw new ApiException(400, self::WRONG_CODE);
        }
        if ($row->attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException(429, self::TOO_MANY);
        }
        if (! hash_equals($row->code_hash, self::hash($email, $purpose, trim($code)))) {
            $row->increment('attempts');
            throw new ApiException($row->attempts >= self::MAX_ATTEMPTS ? 429 : 400,
                $row->attempts >= self::MAX_ATTEMPTS ? self::TOO_MANY : self::WRONG_CODE);
        }

        return $row;
    }

    /** UTC, like the naive DATETIMEs Python writes. */
    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    private static function row(string $email, string $purpose): ?EmailCode
    {
        return EmailCode::where('email', $email)->where('purpose', $purpose)->first();
    }

    private static function hash(string $email, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "$email:$purpose:$code", (string) config('timetable.jwt.secret'));
    }
}
