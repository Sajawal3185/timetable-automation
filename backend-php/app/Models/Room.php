<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** rooms table (backend/app/models/room.py). */
class Room extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'canonical_code', 'capacity', 'room_type', 'building'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
