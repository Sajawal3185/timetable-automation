<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From #93's backend/alembic/versions/2026_11_15_c4b8db037e9e_signup_email_codes.py (pending_signups).
// A signup waiting for its code. NOT an account yet: the users row is only made
// when the right code is entered, so nobody can squat on an address they can't read.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_signups', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->unique();
            $table->string('kind', 10);                  // student | staff
            $table->string('identifier', 150);
            $table->string('name', 100);
            $table->string('password_hash', 255);        // bcrypt, like users
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->cascadeOnDelete();
            $table->string('roll_number', 20)->nullable();
            $table->string('sys_id', 20)->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_signups');
    }
};
