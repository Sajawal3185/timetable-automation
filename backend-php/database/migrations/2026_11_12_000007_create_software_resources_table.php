<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (software_resources).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_resources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->index();
            $table->string('category', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('download_url', 500);
            $table->text('instructions')->nullable();
            $table->foreignId('course_id')->nullable()->index()->constrained('courses');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_resources');
    }
};
