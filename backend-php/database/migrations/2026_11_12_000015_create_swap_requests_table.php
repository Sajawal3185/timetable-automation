<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (swap_requests).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_teacher_id')->constrained('teachers');
            $table->foreignId('entry_id')->constrained('timetable_entries');
            $table->foreignId('target_entry_id')->constrained('timetable_entries');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        // The doubled "ck_swap_requests_" is Alembic's real name; kept so both databases match.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE swap_requests ADD CONSTRAINT ck_swap_requests_ck_swap_requests_different_entries CHECK (entry_id <> target_entry_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('swap_requests');
    }
};
