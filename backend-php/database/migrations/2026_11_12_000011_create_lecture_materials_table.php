<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (lecture_materials).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecture_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->index()->constrained('courses');
            $table->string('title', 200);
            $table->string('material_type', 20)->index();
            $table->string('instructor_name', 100)->index();
            $table->string('term', 40)->nullable();
            $table->text('description')->nullable();
            $table->string('file_path', 300);
            $table->string('original_filename', 255);
            $table->string('content_type', 100)->nullable();
            $table->integer('file_size')->default(0);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecture_materials');
    }
};
