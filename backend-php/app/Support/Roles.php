<?php

namespace App\Support;

use App\Models\User;

/**
 * Role names and groups. PHP twin of the top of backend/app/core/permissions.py.
 * Routes use them through the role middleware: ->middleware('role:'.Roles::list(Roles::STAFF)),
 * or simply ->middleware('role:hod,admin').
 *
 * Swap rules (can_request_swap / can_decide_swap) come with Eisha's swaps module.
 */
final class Roles
{
    public const STUDENT = 'student';

    public const TEACHER = 'teacher';

    public const HOD = 'hod';

    public const ADMIN = 'admin';

    /** Every role that exists. A 'role:' name outside this list is a typo (RoleNamesTest). */
    public const ALL = [self::STUDENT, self::TEACHER, self::HOD, self::ADMIN];

    /** May upload files, manage software, request makeups. */
    public const STAFF = [self::TEACHER, self::HOD, self::ADMIN];

    /** May manage rooms, events and timetable imports. */
    public const ADMINS = [self::HOD, self::ADMIN];

    /** The one 403 message every role check uses (test_role_checks.py). */
    public const DENIED = 'You do not have permission for this action';

    /** The role as a lowercase string, always read from the database row. */
    public static function of(User $user): string
    {
        return strtolower((string) $user->role);
    }

    /** "teacher,hod,admin" for use in a route: 'role:'.Roles::list(Roles::STAFF) */
    public static function list(array $roles): string
    {
        return implode(',', $roles);
    }
}
