<?php

namespace Tests\Unit;

use App\Support\DbTls;
use PHPUnit\Framework\TestCase;

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

    public function test_ca_file_turns_on_verification(): void
    {
        $options = DbTls::options(self::AIVEN, '/etc/secrets/ca.pem');

        $this->assertSame([
            self::attr('SSL_CA') => '/etc/secrets/ca.pem',
            self::attr('SSL_VERIFY_SERVER_CERT') => true,
        ], $options);
    }

    public function test_local_mysql_without_ssl_mode_is_unchanged(): void
    {
        $this->assertSame([], DbTls::options('mysql://u:p@localhost:3306/timetable'));
    }

    public function test_sqlite_and_no_url_pass_through(): void
    {
        $this->assertSame([], DbTls::options('sqlite:///:memory:'));
        $this->assertSame([], DbTls::options(null));
        $this->assertSame([], DbTls::options('', '/etc/secrets/ca.pem'));
    }

    public function test_other_ssl_mode_spellings(): void
    {
        $this->assertSame('verify_ca', DbTls::sslMode('mysql://h/db?sslmode=VERIFY-CA'));
        $this->assertSame('disabled', DbTls::sslMode('mysql://h/db?ssl_mode=DISABLED'));
        $this->assertSame([], DbTls::options('mysql://h/db?ssl_mode=DISABLED'));
    }
}
