<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** pending_signups table (#93's backend/app/models/email_code.py): a signup waiting for its code. */
class PendingSignup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email', 'kind', 'identifier', 'name', 'password_hash', 'department_id', 'section_id',
        'roll_number', 'sys_id', 'created_at',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'section_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
