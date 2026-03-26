<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // ── Primary key ───────────────────────────────────────────────
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ── Core auth ─────────────────────────────────────────────────
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name');
            $table->string('last_name');

            // ── Role: 'teacher' | 'student' ───────────────────────────────
            $table->string('role')->default('student');

            // ── Profile ───────────────────────────────────────────────────
            $table->string('avatar_url')->nullable();
            $table->string('phone')->nullable();
            $table->text('bio')->nullable();

            // ── GitHub — Phase 1: username only; token filled in Phase 2 ──
            $table->string('github_username')->nullable();
            $table->text('github_token_encrypted')->nullable(); // Phase 2: private repo access

            // ── Account state ─────────────────────────────────────────────
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->string('email_verification_token')->nullable();
            $table->string('password_reset_token')->nullable();
            $table->timestamp('password_reset_expires')->nullable();

            // ── Timestamps ────────────────────────────────────────────────
            $table->timestamps();
            $table->timestamp('last_login')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};