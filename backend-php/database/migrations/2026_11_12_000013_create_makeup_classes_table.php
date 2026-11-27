<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (makeup_classes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('makeup_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->index()->constrained('courses')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->date('class_date')->nullable()->index();
            $table->string('day_of_week', 10);
            $table->time('start_time');
            $table->time('end_time');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('scheduled');
            // 1 while scheduled, NULL once cancelled. The database computes it; never
            // set it in code. NULLs never clash in a UNIQUE key, so a cancelled class
            // frees its room slot (uq_makeup_room_slot).
            $table->integer('active_slot')->nullable()->storedAs("CASE WHEN status = 'scheduled' THEN 1 END");
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['room_id', 'day_of_week', 'start_time', 'active_slot'], 'uq_makeup_room_slot');
            $table->index(['room_id', 'day_of_week'], 'ix_makeup_room_day');
            $table->index(['section_id', 'day_of_week'], 'ix_makeup_section_day');
            $table->index(['teacher_id', 'day_of_week'], 'ix_makeup_teacher_day');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE makeup_classes ADD CONSTRAINT ck_makeup_classes_makeup_valid_weekday CHECK (day_of_week COLLATE utf8mb4_bin IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'))");
            DB::statement("ALTER TABLE makeup_classes ADD CONSTRAINT ck_makeup_classes_makeup_status_lowercase CHECK (status COLLATE utf8mb4_bin IN ('scheduled', 'cancelled'))");
            DB::statement('ALTER TABLE makeup_classes ADD CONSTRAINT ck_makeup_classes_makeup_end_after_start CHECK (end_time > start_time)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('makeup_classes');
    }
};
