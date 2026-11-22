<?php

namespace App\Support;

use App\Models\User;

/**
 * Login identifiers (roll number, staff id, numl-f24-26789, or the NUML email).
 * From backend/app/routers/auth.py.
 */
final class Identifiers
{
    /**
     * NUML roll numbers look like "Se-9244330": letters, a hyphen, digits (checked
     * after lowercasing). Only the SHAPE: it can't prove the number is yours, so
     * it's never a login key. Signup uses it for the student form.
     */
    public const ROLL_NUMBER_PATTERN = '/^[a-z]{2,4}-\d{6,8}$/';

    /** "SE-123 " and "se-123" must be the same user: trim, then lowercase. */
    public static function normalize(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    /** The account for a typed "NUML email or ID": the identifier first, then the email. */
    public static function findAccount(string $typed): ?User
    {
        $key = self::normalize($typed);
        $user = User::where('identifier', $key)->first();
        if (! $user && str_contains($key, '@')) {
            $user = User::where('email', $key)->first();
        }

        return $user;
    }
}
