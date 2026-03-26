<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->foreignUuid('course_id')
                  ->constrained('courses')
                  ->cascadeOnDelete();

            // ── Content ───────────────────────────────────────────────────
            $table->string('title');
            $table->text('description')->nullable();

            // 'code' | 'quiz' | 'project'
            $table->string('assignment_type')->default('code');

            $table->decimal('max_score', 8, 2)->default(100);
            $table->timestamp('due_date');
            $table->boolean('late_submission_allowed')->default(false);
            $table->decimal('late_penalty_percentage', 5, 2)->default(0);

            // ── Phase 2 hooks: stored now, populated later ────────────────
            // test_cases structure: [{ id, name, input, expected_output, weight }]
            $table->jsonb('test_cases')->nullable();

            // docker_config structure: { image, memory_limit, cpu_limit, timeout }
            $table->jsonb('docker_config')->nullable();

            // Programming language for the Docker runner
            $table->string('language')->nullable();       // 'python' | 'node' | 'java'

            // requirements.txt, package.json content etc.
            $table->jsonb('requirements')->nullable();

            // ── Visibility ────────────────────────────────────────────────
            $table->boolean('is_published')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
