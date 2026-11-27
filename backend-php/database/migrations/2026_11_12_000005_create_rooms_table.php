<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (rooms).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('canonical_code', 50)->nullable()->unique();
            // NULL = capacity not known yet (no fake default, like Python).
            $table->integer('capacity')->nullable();
            $table->string('room_type', 20)->default('lecture');
            $table->string('building', 50)->nullable()->index();
            $table->dateTime('created_at')->useCurrent();
        });

        // SQLite can't add a CHECK after CREATE TABLE; MySQL (staging, production) gets it.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rooms ADD CONSTRAINT ck_rooms_room_type_lowercase CHECK (room_type COLLATE utf8mb4_bin IN ('lecture', 'lab'))");
            DB::statement('ALTER TABLE rooms ADD CONSTRAINT ck_rooms_room_capacity_positive CHECK (capacity IS NULL OR capacity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
