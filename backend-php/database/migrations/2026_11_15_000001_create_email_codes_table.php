<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// From #93's backend/alembic/versions/2026_11_15_c4b8db037e9e_signup_email_codes.py (email_codes).
// One live code per (email, purpose). Only an HMAC of the code is stored, so a
// database leak doesn't hand out working codes. (The users columns of that
// migration are already in create_users_table.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->string('purpose', 10);               // signup | reset
            $table->string('code_hash', 64);             // hex HMAC-SHA256, never the code
            $table->dateTime('expires_at');
            $table->integer('attempts')->default(0);     // wrong guesses so far
            $table->dateTime('sent_at');                 // for "one code a minute"
            $table->unique(['email', 'purpose'], 'uq_email_code_email_purpose');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE email_codes ADD CONSTRAINT ck_email_codes_email_code_purpose CHECK (purpose COLLATE utf8mb4_bin IN ('signup', 'reset'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_codes');
    }
};
