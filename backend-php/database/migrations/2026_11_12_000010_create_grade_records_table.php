<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (grade_records).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users');
            $table->string('term', 40);
            $table->string('course_code', 20);
            $table->string('course_title', 150)->nullable();
            // Python's sa.Float (a FLOAT column on MySQL).
            $table->float('credit_hours');
            $table->string('grade', 3);
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['user_id', 'term', 'course_code'], 'uq_grade_user_term_course');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_records');
    }
};
