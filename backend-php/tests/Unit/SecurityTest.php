<?php

namespace Tests\Unit;

use App\Services\Security;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use Tests\TestCase;
use UnexpectedValueException;

/** Passwords and tokens (backend/app/core/security.py). */
class SecurityTest extends TestCase
{
    public function test_password_round_trip(): void
    {
        $hash = Security::hashPassword('correct-horse-1');

        $this->assertTrue(Security::verifyPassword('correct-horse-1', $hash));
        $this->assertFalse(Security::verifyPassword('wrong', $hash));
        $this->assertFalse(Security::verifyPassword('', $hash));
        $this->assertFalse(Security::verifyPassword('correct-horse-1', null));
    }

    public function test_python_bcrypt_hashes_still_work(): void
    {
        // Python's bcrypt writes "$2b$...", PHP "$2y$..."; the hash itself is the same algorithm.
        $python = '$2b$'.substr(Security::hashPassword('secret-pass'), 4);

        $this->assertTrue(Security::verifyPassword('secret-pass', $python));
        $this->assertFalse(Security::verifyPassword('other', $python));
    }

    public function test_72_byte_limit_counts_bytes_not_characters(): void
    {
        Security::hashPassword(str_repeat('a', 72));   // exactly at the limit is fine

        $this->expectException(InvalidArgumentException::class);
        Security::hashPassword(str_repeat('é', 37));   // 37 characters, 74 bytes
    }

    public function test_too_long_password_never_verifies(): void
    {
        $this->assertFalse(Security::verifyPassword(str_repeat('a', 73), Security::dummyHash()));
    }

    public function test_empty_password_cannot_be_hashed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Security::hashPassword('');
    }

    public function test_token_carries_sub_role_and_token_version(): void
    {
        $payload = Security::decodeAccessToken(Security::createAccessToken(7, ' HOD ', 3));

        $this->assertSame('7', $payload['sub']);
        $this->assertSame('hod', $payload['role']);
        $this->assertSame(3, $payload['tv']);
        $this->assertEqualsWithDelta(time() + 60 * 60, $payload['exp'], 5);
    }

    public function test_expired_token_is_refused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Security::decodeAccessToken(Security::createAccessToken(7, 'admin', 0, expiresInMinutes: -1));
    }

    public function test_token_signed_with_another_key_is_refused(): void
    {
        $forged = JWT::encode(['sub' => '1', 'exp' => time() + 60], str_repeat('x', 40), 'HS256');

        $this->expectException(UnexpectedValueException::class);
        Security::decodeAccessToken($forged);
    }

    public function test_token_without_numeric_sub_is_refused(): void
    {
        $secret = config('timetable.jwt.secret');
        foreach ([['exp' => time() + 60], ['sub' => 'abc', 'exp' => time() + 60], ['sub' => '1']] as $claims) {
            try {
                Security::decodeAccessToken(JWT::encode($claims, $secret, 'HS256'));
                $this->fail('accepted '.json_encode($claims));
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_garbage_is_refused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Security::decodeAccessToken('not-a-token');
    }
}
