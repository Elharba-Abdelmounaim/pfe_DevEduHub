<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Performance Indexes
    |--------------------------------------------------------------------------
    | Added after Phase 1 + 2 are stable. These cover the most common
    | query patterns identified from controllers and the grading pipeline.
    |
    | Run: php artisan migrate
    | Rollback: php artisan migrate:rollback --step=1
    */

    public function up(): void
    {
        // ── submissions ───────────────────────────────────────────────────────
        Schema::table('submissions', function (Blueprint $table) {
            // Most common student query: "show me my submissions"
            $table->index(['student_id', 'submission_status'], 'idx_sub_student_status');

            // Teacher query: "show all submissions for this assignment"
            $table->index(['assignment_id', 'submission_status'], 'idx_sub_assignment_status');

            // Grading queue worker: "find queued/pending submissions"
            $table->index(['submission_status', 'submitted_at'], 'idx_sub_status_time');

            // is_late reports + late penalty calculations
            $table->index(['is_late', 'submitted_at'], 'idx_sub_late');

            // Retry logic: "how many retries has this submission had?"
            $table->index('retry_count', 'idx_sub_retry_count');
        });

        // ── courses ───────────────────────────────────────────────────────────
        Schema::table('courses', function (Blueprint $table) {
            // Student browsing: "show active courses"
            $table->index(['is_active', 'instructor_id'], 'idx_course_active_instructor');

            // Academic year / semester filtering
            $table->index(['academic_year', 'semester'], 'idx_course_year_semester');
        });

        // ── assignments ───────────────────────────────────────────────────────
        Schema::table('assignments', function (Blueprint $table) {
            // Student view: "published assignments in this course sorted by due date"
            $table->index(['course_id', 'is_published', 'due_date'], 'idx_assign_course_pub_due');

            // Upcoming deadline queries
            $table->index('due_date', 'idx_assign_due_date');
        });

        // ── enrollments ───────────────────────────────────────────────────────
        Schema::table('enrollments', function (Blueprint $table) {
            // "Is this student enrolled in this course?" — checked on every submission
            // Note: unique constraint already exists on (student_id, course_id),
            // but a partial index on active enrollments is faster for auth checks
            $table->index(['student_id', 'course_id', 'status'], 'idx_enroll_student_course_status');

            // Course roster query: "how many active students in this course?"
            $table->index(['course_id', 'status'], 'idx_enroll_course_status');
        });

        // ── users ─────────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            // Role-based filtering (admin panels, teacher listings)
            $table->index(['role', 'is_active'], 'idx_user_role_active');

            // GitHub username lookups (Phase 2 webhook integration)
            $table->index('github_username', 'idx_user_github');
        });

        // ── notifications ─────────────────────────────────────────────────────
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(['notifiable_id', 'notifiable_type', 'read_at'], 'idx_notif_notifiable_read');
            });
        }
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex('idx_sub_student_status');
            $table->dropIndex('idx_sub_assignment_status');
            $table->dropIndex('idx_sub_status_time');
            $table->dropIndex('idx_sub_late');
            $table->dropIndex('idx_sub_retry_count');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('idx_course_active_instructor');
            $table->dropIndex('idx_course_year_semester');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('idx_assign_course_pub_due');
            $table->dropIndex('idx_assign_due_date');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('idx_enroll_student_course_status');
            $table->dropIndex('idx_enroll_course_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_user_role_active');
            $table->dropIndex('idx_user_github');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notif_notifiable_read');
        });
    }
};