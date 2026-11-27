<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// From backend/alembic/versions/2026_11_12_c90e9bbc2e2b_create_tables.py (notifications).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('type', ['schedule_changed', 'swap_requested', 'swap_approved', 'swap_rejected', 'makeup_added']);
            $table->text('message');
            $table->integer('related_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->index(['user_id', 'is_read'], 'ix_notifications_user_id_is_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
