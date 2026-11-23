<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Route middleware 'role': ->middleware('role:hod,admin').
 * PHP twin of require_roles in backend/app/core/permissions.py: the ONE place
 * for role checks. Don't write role checks inside controllers.
 *
 * It logs the user in first (like 'jwt') if that hasn't happened yet, so
 * 'role:admin' on its own is enough. The role comes from the database row.
 */
class RequireRoles
{
    public function __construct(private AuthenticateJwt $jwt) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // The user the 'jwt' check found for THIS request; if it didn't run, run it now.
        $user = $request->attributes->get(AuthenticateJwt::USER) ?? $this->jwt->authenticate($request);

        // trim: Laravel passes 'role:hod, admin' on as "hod" and " admin", and
        // " admin" would silently deny every admin.
        $allowed = array_map(fn (string $role) => strtolower(trim($role)), $roles);
        if (! in_array(Roles::of($user), $allowed, true)) {
            throw new AccessDeniedHttpException(Roles::DENIED);
        }

        return $next($request);
    }
}
