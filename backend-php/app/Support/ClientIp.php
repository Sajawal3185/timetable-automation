<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The caller's address, for the per-address rate limits.
 *
 * Render runs behind Cloudflare. Checked on staging (28 Nov 2026):
 *  - $request->ip() (the last X-Forwarded-For entry, see bootstrap/app.php) is an
 *    address of Cloudflare's or Render's own, and it changes from request to
 *    request: 15 parallel bad logins for one account never hit the 10-per-address limit.
 *  - Cloudflare answers 403 to any request that ARRIVES with a CF-Connecting-IP
 *    header, so a client can't fake it. Cloudflare sets it to the real client address.
 *
 * So: CF-Connecting-IP when it holds a valid IP, else ip() (local runs, CI, tests).
 * Only someone who could reach the container without passing through Cloudflare
 * could choose it, and on Render the container has no public address.
 */
final class ClientIp
{
    public const HEADER = 'CF-Connecting-IP';

    public static function of(Request $request): string
    {
        $cloudflare = trim((string) $request->header(self::HEADER, ''));

        if ($cloudflare !== '' && filter_var($cloudflare, FILTER_VALIDATE_IP) !== false) {
            return $cloudflare;
        }

        return (string) $request->ip();
    }
}
