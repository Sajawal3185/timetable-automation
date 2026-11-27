<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (courses).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('title', 150)->index();
            // NULL = the importer didn't know the credits.
            $table->integer('credit_hours')->nullable();
            $table->boolean('is_lab')->default(false);
            $table->foreignId('department_id')->index()->constrained('departments')->restrictOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['code', 'department_id'], 'uq_course_code_dept');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE courses ADD CONSTRAINT ck_courses_course_credits_positive CHECK (credit_hours IS NULL OR credit_hours > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
