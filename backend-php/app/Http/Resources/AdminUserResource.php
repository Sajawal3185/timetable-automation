<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * A user as the admin's users screen sees it: the normal user object plus the
 * email, whether it was verified, and whether the account is active. Never the
 * password hash.
 *
 * @mixin User
 */
class AdminUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'is_active' => $this->is_active,
        ];
    }
}
