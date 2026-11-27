<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (exam_papers).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->index()->constrained('courses');
            $table->string('title', 200);
            $table->string('term', 40)->index();
            $table->string('exam_type', 20)->index();
            $table->integer('year')->nullable();
            $table->string('instructor_name', 100)->nullable();
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
        Schema::dropIfExists('exam_papers');
    }
};
