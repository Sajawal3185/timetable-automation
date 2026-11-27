<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (timetable_entries).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->string('term', 40)->default('Fall 2026')->index();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('course_id')->index()->constrained('courses')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('day_of_week', 10);
            $table->time('start_time');
            $table->time('end_time');
            // "" rather than NULL, so parallel lab groups can't slip past the UNIQUE key.
            $table->string('group_label', 20)->default('');
            $table->integer('slot_index')->nullable();
            $table->string('block_id', 64)->nullable()->index();
            $table->string('elective_group', 64)->nullable()->index();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(
                ['term', 'section_id', 'course_id', 'day_of_week', 'start_time', 'end_time', 'group_label'],
                'uq_timetable_term_sec_course_slot_grp',
            );
            $table->index(['room_id', 'day_of_week'], 'ix_timetable_room_day');
            $table->index(['section_id', 'day_of_week'], 'ix_timetable_section_day');
            $table->index(['teacher_id', 'day_of_week'], 'ix_timetable_teacher_day');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE timetable_entries ADD CONSTRAINT ck_timetable_entries_timetable_valid_weekday CHECK (day_of_week COLLATE utf8mb4_bin IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'))");
            DB::statement('ALTER TABLE timetable_entries ADD CONSTRAINT ck_timetable_entries_timetable_end_after_start CHECK (end_time > start_time)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
    }
};
