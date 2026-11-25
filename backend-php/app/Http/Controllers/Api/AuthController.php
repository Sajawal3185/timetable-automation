<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Department;
use App\Models\User;
use App\Services\Security;
use App\Support\Identifiers;
use App\Support\Roles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/auth: login, activate, me, users. Port of backend/app/routers/auth.py.
 * THE WORKED EXAMPLE: copy its shape for every other router (CONVENTIONS.md).
 *
 * Rules:
 *  - ONE login for every role. The role comes from the database row, never the request.
 *  - Accounts come from: roster students claiming theirs (activate), an admin
 *    creating staff (users), and email-code signup (SignupController, from #93).
 */
class AuthController extends Controller
{
    /** Roles an admin may create. "student" is missing on purpose: students come from the roster. */
    private const STAFF_ROLES = ['admin', 'hod', 'teacher'];   // sorted, as in the error message

    /**
     * Log in. Same answer for every failure, so nobody can find out which
     * accounts exist.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = Identifiers::findAccount($request->validated('identifier'));

        // Always ONE bcrypt check, even for an unknown user or one with no
        // password yet: returning early would answer in 1 ms instead of 100 ms
        // and give away which identifiers exist.
        $hash = $user?->password_hash ?: Security::dummyHash();
        $passwordOk = Security::verifyPassword($request->validated('password'), $hash);

        if (! $user || ! $user->password_hash || ! $passwordOk || ! $user->is_active) {
            abort(401, 'Invalid credentials');
        }

        return $this->loggedIn($user);
    }

    /**
     * A rostered student sets their first password and is logged in.
     *
     * @unauthenticated
     */
    public function activate(ActivateRequest $request): JsonResponse
    {
        $user = User::where('identifier', Identifiers::normalize($request->validated('identifier')))->first();

        // Claimable = exists + student + no password yet. One vague message for
        // every failure, so this can't be used to check who is on the roster.
        $claimable = $user && $user->role === Roles::STUDENT && $user->password_hash === null;
        if (! $claimable || ! $user->is_active) {
            abort(400, 'This account cannot be activated');
        }

        // Set the password ONLY IF it's still empty, in one UPDATE. Two requests
        // racing for the same account both pass the check above; this way the
        // database lets exactly one win, and the other gets the same 400.
        $hash = Security::hashPassword($request->validated('new_password'));
        $claimed = User::whereKey($user->id)->whereNull('password_hash')->update(['password_hash' => $hash]);
        if ($claimed !== 1) {
            abort(400, 'This account cannot be activated');
        }
        $user->password_hash = $hash;

        return $this->loggedIn($user);
    }

    /** Who am I. The frontend calls it on page refresh. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** Admin creates a teacher, HOD or admin account. */
    public function createUser(CreateUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! in_array($data['role'], self::STAFF_ROLES, true)) {
            abort(400, 'role must be one of: '.implode(', ', self::STAFF_ROLES));
        }
        // A HOD without a department could never decide any swap (BE-07).
        $departmentId = $data['department_id'] ?? null;
        if ($data['role'] === Roles::HOD && $departmentId === null) {
            abort(400, 'department_id is required when role is hod');
        }
        if ($departmentId !== null && ! Department::whereKey($departmentId)->exists()) {
            abort(400, 'Department not found');
        }

        // Friendly early check, so the usual duplicate gets a 409 without hashing first...
        $identifier = Identifiers::normalize($data['identifier']);
        if (User::where('identifier', $identifier)->exists()) {
            abort(409, 'Identifier already exists');
        }

        // ...but two requests at the same moment can both pass it; the UNIQUE
        // index then refuses the second, which must be a 409 too, not a 500.
        try {
            $user = User::create([
                'name' => trim($data['name']),
                'identifier' => $identifier,
                'password_hash' => Security::hashPassword($data['password']),
                'role' => $data['role'],
                'department_id' => $departmentId,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Identifier already exists');
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /** {token, user}: the answer of login and activate. */
    private function loggedIn(User $user): JsonResponse
    {
        $token = Security::createAccessToken($user->id, $user->role, (int) $user->token_version);

        return response()->json(['token' => $token, 'user' => new UserResource($user)]);
    }
}
