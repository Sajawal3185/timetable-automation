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

    // --- Fixtures made by the real Python backend (bcrypt 5, PyJWT 2) ----------

    /** bcrypt.hashpw(b"python-made-pass", bcrypt.gensalt(rounds=4)) */
    private const PYTHON_HASH = '$2b$04$4cA3PLOXw3LUYTH9t914ZO4BzzlQaBpTNaM1G5JdBiM.Vy3xCpX3q';

    /**
     * jwt.encode({"sub": "1", "role": "admin", "exp": 2100-01-01}, <phpunit.xml secret>, "HS256"),
     * the payload create_access_token makes (no "tv": Python tokens never had one).
     */
    public const PYTHON_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIiwicm9sZSI6ImFkbWluIiwiZXhwIjo0MTAyNDQ0ODAwfQ.OYKODkXzLd8qg_1ngxXzAzMjwNgDmRh1byVoLOrd_Dg';

    public function test_a_hash_made_by_python_verifies(): void
    {
        $this->assertTrue(Security::verifyPassword('python-made-pass', self::PYTHON_HASH));
        $this->assertFalse(Security::verifyPassword('python-made-pas', self::PYTHON_HASH));
    }

    public function test_a_token_made_by_python_decodes(): void
    {
        $payload = Security::decodeAccessToken(self::PYTHON_TOKEN);

        $this->assertSame('1', $payload['sub']);
        $this->assertArrayNotHasKey('tv', $payload);
    }

    // --- Password edge cases -------------------------------------------------------

    public function test_72_bytes_is_the_limit_for_ascii_and_multibyte(): void
    {
        $ascii72 = str_repeat('a', 72);
        $multibyte72 = str_repeat('é', 36);          // 36 characters, 72 bytes
        foreach ([$ascii72, $multibyte72] as $password) {
            $this->assertTrue(Security::verifyPassword($password, Security::hashPassword($password)));
        }

        foreach ([str_repeat('a', 73), str_repeat('é', 36).'a'] as $password) {   // 73 bytes each
            try {
                Security::hashPassword($password);
                $this->fail('hashed a 73-byte password');
            } catch (InvalidArgumentException) {
                // bcrypt itself only reads 72 bytes, so the hash of the first 72
                // WOULD match this password; verifyPassword must refuse it anyway.
                $first72 = Security::hashPassword(substr($password, 0, 72));
                $this->assertTrue(password_verify($password, $first72), 'bcrypt should ignore byte 73');
                $this->assertFalse(Security::verifyPassword($password, $first72));
            }
        }
    }

    public function test_the_password_0_works(): void
    {
        $this->assertTrue(Security::verifyPassword('0', Security::hashPassword('0')));
    }

    public function test_nul_bytes_are_refused(): void
    {
        $withNul = 'abc'.chr(0).'def';
        $this->assertFalse(Security::verifyPassword($withNul, Security::hashPassword('abc')));

        $this->expectException(InvalidArgumentException::class);
        Security::hashPassword($withNul);
    }

    // --- The dummy hash (login timing) ----------------------------------------------

    public function test_dummy_hash_cost_matches_bcrypt_rounds(): void
    {
        foreach ([4, 12] as $rounds) {
            config(['hashing.bcrypt.rounds' => $rounds]);
            $dummy = Security::dummyHash();

            $this->assertSame($rounds, password_get_info($dummy)['options']['cost']);
            $this->assertSame($rounds, password_get_info(Security::hashPassword('x'))['options']['cost']);
            $this->assertSame($dummy, Security::dummyHash(), 'made once, not per call');
        }
        $this->assertTrue(Security::verifyPassword('timing-dummy', Security::dummyHash()));   // the cost-12 constant
    }

    // --- The JWT secret and algorithm ------------------------------------------------

    public function test_secret_rule(): void
    {
        $this->assertSame('JWT_SECRET_KEY is not set', Security::secretProblem(null));
        $this->assertSame('JWT_SECRET_KEY is not set', Security::secretProblem('   '));
        $this->assertStringContainsString('at least 32', Security::secretProblem(str_repeat('x', 31)));
        $this->assertNull(Security::secretProblem(str_repeat('x', 32)));
        $this->assertGreaterThanOrEqual(32, strlen(config('timetable.jwt.secret')), 'phpunit.xml secret');
    }

    public function test_a_short_secret_is_a_500_not_a_silent_401(): void
    {
        $token = Security::createAccessToken(1, 'admin');
        config(['timetable.jwt.secret' => 'only-10-ch']);

        // RuntimeException (500), NOT UnexpectedValueException (which callers turn into 401).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32');
        Security::decodeAccessToken($token);
    }

    public function test_other_algorithms_are_refused(): void
    {
        $secret = config('timetable.jwt.secret');
        $claims = ['sub' => '1', 'exp' => time() + 60];
        $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $tokens = [
            'HS512' => JWT::encode($claims, str_pad($secret, 64, 'x'), 'HS512'),   // HS512 needs a 64-byte key
            'none' => $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64($claims).'.',
        ];
        foreach ($tokens as $alg => $token) {
            try {
                Security::decodeAccessToken($token);
                $this->fail("accepted alg $alg");
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
