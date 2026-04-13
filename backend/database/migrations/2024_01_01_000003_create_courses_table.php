<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            // ── Primary key ───────────────────────────────────────────────
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
 
            // ── Ownership ─────────────────────────────────────────────────
            $table->foreignUuid('instructor_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
 
            // ── Identity ─────────────────────────────────────────────────
            $table->string('code')->unique();          // e.g. "CS301"
            $table->string('title');
            $table->text('description')->nullable();
 
            // ── Academic metadata ─────────────────────────────────────────
            $table->integer('academic_year');          // e.g. 2024
            $table->string('semester');                // "Fall" | "Spring" | "Summer"
            $table->integer('credits')->default(3);
            $table->integer('max_students')->default(30);
 
            // ── State ─────────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);
 
            $table->timestamps();
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
 
