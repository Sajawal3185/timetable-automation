<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (events).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->enum('event_type', ['deadline', 'event', 'holiday', 'department'])->index();
            $table->text('description')->nullable();
            $table->date('start_date')->index();
            $table->date('end_date')->nullable();
            $table->string('location', 150)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->dateTime('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE events ADD CONSTRAINT ck_events_check_end_date_after_start CHECK ((end_date IS NULL) OR (end_date >= start_date))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
