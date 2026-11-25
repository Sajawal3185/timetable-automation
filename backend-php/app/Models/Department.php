<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** departments table (backend/app/models/department.py). */
class Department extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'code'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
