<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ── Foreign keys ─────────────────────────────────────────────
            $table->foreignUuid('assignment_id')
                  ->constrained('assignments')
                  ->cascadeOnDelete();

            $table->foreignUuid('student_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // ── GitHub submission data ────────────────────────────────────
            $table->string('github_repo_url');
            $table->string('github_commit_sha', 40)->nullable(); // Pin exact commit for grader
            $table->string('github_branch')->default('main');

            // 'github' | 'file_upload'
            $table->string('submission_type')->default('github');
            $table->string('file_path')->nullable();

            // ── Submission state ─────────────────────────────────────────
            // 'pending' | 'queued' | 'grading' | 'graded' | 'failed'
            $table->string('submission_status')->default('pending');
            $table->timestamp('submitted_at')->useCurrent();
            $table->boolean('is_late')->default(false); // Set at store() time

            // ── Phase 2: Scoring (all nullable — Phase 1 leaves them empty) ──
            // Written by the Python Auto-Grader microservice
            $table->decimal('auto_grade_score', 8, 2)->nullable();

            // Written by teacher after manual review
            $table->decimal('manual_grade_score', 8, 2)->nullable();

            // final_score = auto_grade_score or manual override
            $table->decimal('final_score', 8, 2)->nullable();

            // ── Feedback ─────────────────────────────────────────────────
            $table->text('teacher_feedback')->nullable();
            $table->text('student_notes')->nullable();

            // ── Phase 2: Retry support ────────────────────────────────────
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
