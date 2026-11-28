<?php

namespace App\Support;

/** backend/app/utils/instructor.py: one rule for the instructor name on exam papers and lecture materials. */
final class InstructorName
{
    /**
     * The instructor name to store.
     *  1. A teacher's upload is always filed under their own name, so a teacher
     *     cannot put files under a colleague's name.
     *  2. An HOD or admin may name another instructor: a given name that is not blank is used (trimmed).
     *  3. Otherwise the uploader's own name is used ("Unknown" if they have none).
     *
     * $uploader is anything with name and role (a User, or a plain object in tests).
     */
    public static function resolve(?string $given, object $uploader): string
    {
        $own = Text::strip((string) ($uploader->name ?? ''));
        if ($own === '') {
            $own = 'Unknown';
        }
        if (strtolower((string) ($uploader->role ?? '')) === 'teacher') {   // role check ignores case
            return $own;
        }
        $given = Text::strip($given);

        return $given !== '' ? $given : $own;
    }
}
