<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Password hashing and JWT tokens. PHP twin of backend/app/core/security.py.
 * Pure functions: no database, no models.
 */
class Security
{
    /** bcrypt only looks at the first 72 BYTES; longer passwords are refused, never cut. */
    public const MAX_PASSWORD_BYTES = 72;

    private static ?string $dummyHash = null;

    public static function hashPassword(string $password): string
    {
        if ($password === '') {
            throw new InvalidArgumentException('Password cannot be empty');
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Password cannot exceed '.self::MAX_PASSWORD_BYTES.' UTF-8 bytes');
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) config('hashing.bcrypt.rounds', 12)]);
    }

    public static function verifyPassword(?string $plain, ?string $hash): bool
    {
        if (! $plain || ! $hash || strlen($plain) > self::MAX_PASSWORD_BYTES) {
            return false;
        }

        // Also accepts the $2b$ hashes Python's bcrypt writes.
        return password_verify($plain, $hash);
    }

    /**
     * A real bcrypt hash of a throwaway password. Login checks against it when
     * the account doesn't exist, so "unknown user" takes as long as "wrong
     * password" and response times can't be used to list valid accounts.
     */
    public static function dummyHash(): string
    {
        return self::$dummyHash ??= self::hashPassword('timing-dummy');
    }

    /** A signed JWT with sub (user id as a string), lowercase role, exp and tv (token_version). */
    public static function createAccessToken(int $userId, string $role, int $tokenVersion = 0, ?int $expiresInMinutes = null): string
    {
        $minutes = $expiresInMinutes ?? config('timetable.jwt.expire_minutes');
        $payload = [
            'sub' => (string) $userId,
            'role' => strtolower(trim($role)),
            'exp' => time() + $minutes * 60,
            'tv' => $tokenVersion,
        ];

        return JWT::encode($payload, self::secret(), config('timetable.jwt.algorithm'));
    }

    /**
     * Decode and check a token; exp and a numeric sub are required.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException for ANY bad token (bad signature, expired, garbage, missing claim)
     */
    public static function decodeAccessToken(string $token): array
    {
        $key = new Key(self::secret(), config('timetable.jwt.algorithm'));   // a missing secret is a 500, not a 401
        try {
            $payload = (array) JWT::decode($token, $key);
        } catch (\Throwable $e) {
            // firebase/php-jwt throws several exception types; callers only need "invalid".
            throw new UnexpectedValueException('Invalid token', previous: $e);
        }

        if (! isset($payload['exp'], $payload['sub']) || ! ctype_digit((string) $payload['sub'])) {
            throw new UnexpectedValueException('Invalid token');
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = (string) config('timetable.jwt.secret');
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET_KEY is not set');
        }

        return $secret;
    }
}
