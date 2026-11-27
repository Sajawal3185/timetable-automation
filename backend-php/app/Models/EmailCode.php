<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** email_codes table (#93's backend/app/models/email_code.py). Written only by App\Services\EmailCodes. */
class EmailCode extends Model
{
    public $timestamps = false;

    protected $fillable = ['email', 'purpose', 'code_hash', 'expires_at', 'attempts', 'sent_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
