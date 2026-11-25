<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The user object (UserOut in auth.py). Exactly these fields, in this order;
 * never the password hash.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'identifier' => $this->identifier,
            // HODs load their department's swaps, students their section's timetable.
            'department_id' => $this->department_id,
            'section_id' => $this->section_id,
            // The linked teacher record (teachers.user_id); the timetable filters
            // "my classes" by it. null for students and unlinked staff.
            // For a list of users, ->load('teacher') first: one query, not one per user.
            'teacher_id' => $this->teacher?->id,
        ];
    }
}
