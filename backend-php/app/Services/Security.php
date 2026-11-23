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

    /** firebase/php-jwt refuses shorter HS256 keys; Python's settings check the same. */
    public const MIN_SECRET_BYTES = 32;

    /** Tokens are always HS256 (not configurable, so a bad env value can't weaken them). */
    public const JWT_ALGORITHM = 'HS256';

    /**
     * bcrypt("timing-dummy") at cost 12, Laravel's default BCRYPT_ROUNDS.
     * Precomputed because PHP forgets static variables between requests: making
     * it per request would cost an unknown user TWO bcrypt runs and a wrong
     * password one, which is exactly the timing gap this hash exists to hide.
     */
    private const DUMMY_HASH_COST_12 = '$2y$12$DTzpTbOcxud8.ADC9teNIuMRpn3aH0QFj5Q2xWk0vYS7OrBJGTZUG';

    private static ?string $dummyHash = null;

    public static function hashPassword(string $password): string
    {
        if ($password === '') {
            throw new InvalidArgumentException('Password cannot be empty');
        }
        if (str_contains($password, "\0")) {
            // bcrypt would stop reading at the NUL byte; refuse instead of storing a shorter password.
            throw new InvalidArgumentException('Password cannot contain NUL bytes');
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Password cannot exceed '.self::MAX_PASSWORD_BYTES.' UTF-8 bytes');
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    public static function verifyPassword(?string $plain, ?string $hash): bool
    {
        // === '' and not ! $plain: "0" is a valid password.
        if ($plain === null || $plain === '' || $hash === null || $hash === ''
            || strlen($plain) > self::MAX_PASSWORD_BYTES || str_contains($plain, "\0")) {
            return false;
        }

        // Also accepts the $2b$ hashes Python's bcrypt writes.
        return password_verify($plain, $hash);
    }

    /**
     * A real bcrypt hash of a throwaway password. Login checks against it when
     * the account doesn't exist, so "unknown user" takes as long as "wrong
     * password" and response times can't be used to list valid accounts.
     * Its cost always equals BCRYPT_ROUNDS, or it would take a different time.
     */
    public static function dummyHash(): string
    {
        $cost = self::cost();
        if ($cost === 12) {
            return self::DUMMY_HASH_COST_12;
        }

        // Other costs (tests use 4): made once per process. Only production's
        // timing matters, and production uses 12.
        if (self::$dummyHash === null || password_get_info(self::$dummyHash)['options']['cost'] !== $cost) {
            self::$dummyHash = self::hashPassword('timing-dummy');
        }

        return self::$dummyHash;
    }

    /** The bcrypt cost factor (BCRYPT_ROUNDS, default 12). */
    public static function cost(): int
    {
        return (int) config('hashing.bcrypt.rounds', 12);
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

        return JWT::encode($payload, self::secret(), self::JWT_ALGORITHM);
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
        $key = new Key(self::secret(), self::JWT_ALGORITHM);   // a bad secret is a 500 (outside the try), not a 401
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

    /**
     * What's wrong with this JWT secret, or null if it's usable. The ONE rule,
     * used here and by AppServiceProvider at boot.
     */
    public static function secretProblem(?string $secret): ?string
    {
        if ($secret === null || trim($secret) === '') {
            return 'JWT_SECRET_KEY is not set';
        }
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            return 'JWT_SECRET_KEY must be at least '.self::MIN_SECRET_BYTES.' characters';
        }

        return null;
    }

    private static function secret(): string
    {
        $secret = config('timetable.jwt.secret');
        if ($problem = self::secretProblem($secret)) {
            // Thrown here, not inside decodeAccessToken's try: a short key must be
            // a loud 500, never a silent "Invalid token" 401 on every request.
            throw new \RuntimeException($problem);
        }

        return $secret;
    }
}
