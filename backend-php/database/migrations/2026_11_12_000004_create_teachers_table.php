<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (teachers).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->index();
            $table->string('normalized_name', 100)->nullable()->unique();
            $table->foreignId('department_id')->nullable()->index()->constrained('departments')->nullOnDelete();
            // The account linked to this teacher record (/api/auth/me reads it as teacher_id).
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
