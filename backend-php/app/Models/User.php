<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * users table (backend/app/models/user.py).
 *
 * @property int $id
 * @property string $name
 * @property string $identifier login key, trimmed + lowercased
 * @property string|null $email
 * @property string|null $password_hash NULL = not activated yet
 * @property string $role student | teacher | hod | admin
 * @property int|null $department_id
 * @property int|null $section_id
 * @property bool $is_active
 * @property int $token_version
 */
class User extends Authenticatable
{
    use HasFactory;

    public const UPDATED_AT = null;   // the table only has created_at

    protected $table = 'users';

    protected $fillable = [
        'name', 'identifier', 'email', 'email_verified_at', 'roll_number', 'sys_id',
        'password_hash', 'role', 'department_id', 'section_id', 'is_active', 'token_version',
    ];

    // Never sent back by accident (API Resources pick fields explicitly anyway).
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'token_version' => 'integer',
            'department_id' => 'integer',
            'section_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** The teacher record linked to this account (teachers.user_id), if any. */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }
}
