<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (sections).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->foreignId('department_id')->index()->constrained('departments')->restrictOnDelete();
            $table->integer('semester')->nullable();
            $table->integer('student_count')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['name', 'department_id'], 'uq_section_name_dept');
        });

        // SQLite can't add a CHECK after CREATE TABLE; MySQL (staging, production) gets it.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sections ADD CONSTRAINT ck_sections_student_count_positive CHECK (student_count IS NULL OR student_count > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
