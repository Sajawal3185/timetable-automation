<?php

namespace Tests\Unit;

use App\Support\DbTls;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Port of backend/tests/test_db_url.py: TLS for hosted MySQL (Aiven). */
class DbTlsTest extends TestCase
{
    private const AIVEN = 'mysql://avnadmin:p%40ss@mysql-x.aivencloud.com:10955/defaultdb?ssl-mode=REQUIRED';

    private static function attr(string $name): int
    {
        return defined("Pdo\\Mysql::ATTR_$name") ? constant("Pdo\\Mysql::ATTR_$name") : constant("PDO::MYSQL_ATTR_$name");
    }

    public function test_aiven_url_turns_tls_on_without_verification(): void
    {
        $options = DbTls::options(self::AIVEN);

        $this->assertArrayHasKey(self::attr('SSL_CAPATH'), $options);
        $this->assertFalse($options[self::attr('SSL_VERIFY_SERVER_CERT')]);
        $this->assertSame('required', DbTls::sslMode(self::AIVEN));
    }

    private static string $ca;

    public static function setUpBeforeClass(): void
    {
        self::$ca = tempnam(sys_get_temp_dir(), 'ca');   // DbTls only checks that the file exists
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$ca);
    }

    public function test_ca_file_turns_on_verification(): void
    {
        $verified = [self::attr('SSL_CA') => self::$ca, self::attr('SSL_VERIFY_SERVER_CERT') => true];

        $this->assertSame($verified, DbTls::options(self::AIVEN, self::$ca));
        $this->assertSame($verified, DbTls::options('mysql://h/db?ssl-mode=VERIFY_IDENTITY', self::$ca));
        // DB_HOST/DB_PORT style (no DB_URL): the CA still applies.
        $this->assertSame($verified, DbTls::options(null, self::$ca));
        $this->assertSame($verified, DbTls::options('', self::$ca));
    }

    public function test_missing_ca_file_stops_startup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_SSL_CA file not found');
        DbTls::options(self::AIVEN, '/etc/secrets/no-such-ca.pem');
    }

    public function test_verify_modes_without_a_ca_stop_startup(): void
    {
        foreach (['VERIFY_CA', 'verify-identity'] as $mode) {
            try {
                DbTls::options("mysql://h/db?ssl-mode=$mode");
                $this->fail("$mode connected without verifying");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('needs DB_SSL_CA', $e->getMessage());
            }
        }
    }

    public function test_local_mysql_without_ssl_mode_is_unchanged(): void
    {
        $this->assertSame([], DbTls::options('mysql://u:p@localhost:3306/timetable'));
    }

    public function test_sqlite_and_no_url_pass_through(): void
    {
        $this->assertSame([], DbTls::options('sqlite:///:memory:'));
        $this->assertSame([], DbTls::options(null));
        $this->assertSame([], DbTls::options('sqlite:///:memory:', self::$ca));
    }

    public function test_other_ssl_mode_spellings(): void
    {
        $this->assertSame('verify_ca', DbTls::sslMode('mysql://h/db?sslmode=VERIFY-CA'));
        $this->assertSame('disabled', DbTls::sslMode('mysql://h/db?ssl_mode=DISABLED'));
        $this->assertSame([], DbTls::options('mysql://h/db?ssl_mode=DISABLED'));
    }
}
