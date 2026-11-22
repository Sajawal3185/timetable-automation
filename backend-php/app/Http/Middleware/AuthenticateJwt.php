<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Security;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use UnexpectedValueException;

/**
 * Route middleware 'jwt': the request must carry a valid "Authorization: Bearer <token>".
 * PHP twin of get_current_user in backend/app/dependencies.py.
 *
 * After it runs, $request->user() is the live User row from the database.
 * Every failure is a 401, which the frontend uses to send people back to login.
 */
class AuthenticateJwt
{
    /** Request attribute holding the user this request authenticated as. */
    public const USER = 'jwt_user';

    public function handle(Request $request, Closure $next): Response
    {
        $this->authenticate($request);

        return $next($request);
    }

    public function authenticate(Request $request): User
    {
        // Step 1: a Bearer token must be present.
        $token = $request->bearerToken();
        if (! $token) {
            throw self::unauthorized('Not authenticated');
        }

        // Step 2: correctly signed, not expired, numeric sub. One message for
        // every way it can be wrong, so callers learn nothing about which check failed.
        try {
            $payload = Security::decodeAccessToken($token);
        } catch (UnexpectedValueException) {
            throw self::unauthorized('Invalid or expired token');
        }

        // Step 3: load the user from the DATABASE on every request. The token's
        // "role" claim is ignored on purpose: demoting or deactivating someone
        // must take effect on their very next request, not when the token expires.
        $user = User::find((int) $payload['sub']);

        // Step 4: still exists and active. Same 401 for both.
        if (! $user || ! $user->is_active) {
            throw self::unauthorized('User not found or inactive');
        }

        // Step 5: a password reset bumps token_version, logging the account out
        // everywhere. Tokens without "tv" count as 0.
        if ((int) ($payload['tv'] ?? 0) !== (int) $user->token_version) {
            throw self::unauthorized('Invalid or expired token');
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(self::USER, $user);

        return $user;
    }

    private static function unauthorized(string $detail): UnauthorizedHttpException
    {
        // Adds the WWW-Authenticate: Bearer header the HTTP spec asks for with a 401.
        return new UnauthorizedHttpException('Bearer', $detail);
    }
}
