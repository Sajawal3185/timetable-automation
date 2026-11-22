<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (users), with
// #93's 2026_11_15_c4b8db037e9e_signup_email_codes.py columns folded in
// (email_verified_at, roll_number, sys_id, token_version; identifier widened to 150).
// One migration instead of two because every PHP database starts empty.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // The login key, stored trimmed + lowercased.
            $table->string('identifier', 150)->unique();
            $table->string('email', 150)->nullable()->unique();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('roll_number', 20)->nullable();
            $table->string('sys_id', 20)->nullable();
            // NULL = roster student who hasn't activated their account yet.
            $table->string('password_hash', 255)->nullable();
            $table->string('role', 20)->default('student')->index();
            $table->foreignId('department_id')->nullable()->index()->constrained('departments')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->index()->constrained('sections')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            // Bumped by reset-password; tokens carrying an older number are refused.
            $table->integer('token_version')->default(0);
            $table->dateTime('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_user_role_lowercase CHECK (role IN ('student', 'teacher', 'hod', 'admin'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
