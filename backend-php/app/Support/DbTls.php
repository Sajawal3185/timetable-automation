<?php

namespace App\Support;

/**
 * TLS options for hosted MySQL (Aiven). PHP twin of backend/app/db_url.py.
 *
 * Aiven hands out  mysql://avnadmin:PASS@host:port/defaultdb?ssl-mode=REQUIRED
 * and refuses unencrypted connections. Laravel reads DB_URL as-is (the extra
 * ssl-mode parameter is ignored), but PDO only turns TLS on when it is given
 * an SSL option, so config/database.php asks this class for them:
 *
 *   DB_SSL_CA set           -> encrypt AND verify the server with that CA file
 *   ssl-mode asks for TLS   -> encrypt, don't verify (fine for a demo,
 *                              not for real student data: set DB_SSL_CA)
 *   neither, or not MySQL   -> no options (local MySQL, SQLite)
 */
class DbTls
{
    private const SSL_ON = ['required', 'verify_ca', 'verify_identity', 'preferred'];

    /** @return array<int, mixed> PDO options for the mysql connection */
    public static function options(?string $url, ?string $caFile = null): array
    {
        if (! $url || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['mysql', 'mariadb'], true)) {
            return [];
        }

        if ($caFile) {
            return [self::attr('SSL_CA') => $caFile, self::attr('SSL_VERIFY_SERVER_CERT') => true];
        }

        if (in_array(self::sslMode($url), self::SSL_ON, true)) {
            // PDO has no "TLS on" switch: TLS starts when any SSL option is set.
            // Pointing it at the system CA folder turns it on; verification is off.
            return [self::attr('SSL_CAPATH') => self::systemCaDir(), self::attr('SSL_VERIFY_SERVER_CERT') => false];
        }

        return [];
    }

    /** "required", "verify_ca", ... from ?ssl-mode= / ssl_mode= / sslmode=, or null. */
    public static function sslMode(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['ssl-mode', 'ssl_mode', 'sslmode'] as $key) {
            if (isset($query[$key])) {
                return str_replace('-', '_', strtolower((string) $query[$key]));
            }
        }

        return null;
    }

    /** The PDO MySQL constant, using the PHP 8.4+ name when it exists. */
    private static function attr(string $name): int
    {
        return defined("Pdo\\Mysql::ATTR_$name")
            ? constant("Pdo\\Mysql::ATTR_$name")
            : constant("PDO::MYSQL_ATTR_$name");
    }

    private static function systemCaDir(): string
    {
        $dir = openssl_get_cert_locations()['default_cert_dir'] ?? '';

        return is_dir($dir) ? $dir : '/etc/ssl/certs';
    }
}
