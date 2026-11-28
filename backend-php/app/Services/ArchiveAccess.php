<?php

namespace App\Services;

use App\Models\User;
use App\Support\Roles;

/**
 * Who may delete an uploaded exam paper or lecture material. A rule that needs
 * data lives in a service, not in the controller (CONVENTIONS.md "Who may call it").
 */
final class ArchiveAccess
{
    /** The uploader, or an admin/HOD (Python: uploaded_by == user.id or role_of(user) in ADMINS). */
    public static function canDelete(User $user, ?int $uploadedBy): bool
    {
        return ($uploadedBy !== null && $uploadedBy === $user->id)
            || in_array(Roles::of($user), Roles::ADMINS, true);
    }
}
