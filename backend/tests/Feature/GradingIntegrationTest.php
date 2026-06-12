<?php

namespace Tests\Feature;

use App\Jobs\GradeSubmissionJob;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\GradingCompletedNotification;
use App\Notifications\GradingFailedNotification;
use App\Services\GraderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GradingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────
    private function makeTeacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    private function makeStudent(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function makeGradableAssignment(User $teacher): Assignment
    {
        $course = Course::factory()->for($teacher, 'instructor')->create();

        return Assignment::factory()->for($course)->create([
            'is_published' => true,
            'due_date'     => now()->addDays(7),
            'test_cases'   => [
                ['id' => 'tc1', 'name' => 'Test 1', 'weight' => 50, 'strategy' => 'exit_zero'],
                ['id' => 'tc2', 'name' => 'Test 2', 'weight' => 50, 'strategy' => 'has_output'],
            ],
            'docker_config' => ['image' => 'python:3.11-slim', 'timeout' => 30],
            'language'      => 'python',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // JOB DISPATCH TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_submission_dispatches_grading_job_for_auto_gradable_assignment(): void
    {
        Queue::fake();
        Notification::fake();

        $teacher    = $this->makeTeacher();
        $assignment = $this->makeGradableAssignment($teacher);
        $student    = $this->makeStudent();

        Enrollment::create([
            'student_id' => $student->id,
            'course_id'  => $assignment->course_id,
            'status'     => 'active',
        ]);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/project',
             ])
             ->assertCreated()
             ->assertJsonFragment(['submission_status' => 'queued']);

        Queue::assertPushedOn('grading', GradeSubmissionJob::class);
    }

    public function test_submission_stays_pending_for_non_auto_gradable_assignment(): void
    {
        Queue::fake();

        $teacher = $this->makeTeacher();
        $course  = Course::factory()->for($teacher, 'instructor')->create();
        $assign  = Assignment::factory()->for($course)->create([
            'is_published' => true,
            'due_date'     => now()->addDays(7),
            'test_cases'   => null,      // no test cases — not auto-gradable
            'docker_config' => null,
        ]);
        $student = $this->makeStudent();
        Enrollment::create(['student_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assign->id,
                 'github_repo_url' => 'https://github.com/student/project',
             ])
             ->assertCreated()
             ->assertJsonFragment(['submission_status' => 'pending']);

        Queue::assertNothingPushed();
    }

    // ═════════════════════════════════════════════════════════════════════
    // JOB HANDLE TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_grade_job_updates_submission_on_success(): void
    {
        Notification::fake();

        $teacher    = $this->makeTeacher();
        $assignment = $this->makeGradableAssignment($teacher);
        $student    = $this->makeStudent();
        Enrollment::create(['student_id' => $student->id, 'course_id' => $assignment->course_id, 'status' => 'active']);

        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $student->id,
            'github_repo_url'   => 'https://github.com/student/project',
            'submission_status' => 'queued',
            'submitted_at'      => now(),
            'is_late'           => false,
            'retry_count'       => 0,
        ]);

        // Mock the grader API service
        $mockGrader = Mockery::mock(GraderApiService::class);
        $mockGrader->shouldReceive('grade')
            ->once()
            ->with($submission->id, $submission->github_repo_url, null)
            ->andReturn([
                'status'         => 'success',
                'score'          => 87.5,
                'passed_tests'   => 2,
                'total_tests'    => 2,
                'logs'           => 'All tests passed',
                'feedback'       => "✓ Exit code 0\n✓ Has output",
                'execution_time' => 4.2,
            ]);

        $this->app->instance(GraderApiService::class, $mockGrader);

        // Run job synchronously
        (new GradeSubmissionJob($submission))->handle($mockGrader);

        $submission->refresh();
        $this->assertEquals('graded',  $submission->submission_status);
        $this->assertEquals(87.5,      (float) $submission->auto_grade_score);
        $this->assertEquals(87.5,      (float) $submission->final_score);

        Notification::assertSentTo($student, GradingCompletedNotification::class);
    }

    public function test_grade_job_marks_failed_on_exception(): void
    {
        Notification::fake();

        $teacher    = $this->makeTeacher();
        $assignment = $this->makeGradableAssignment($teacher);
        $student    = $this->makeStudent();
        Enrollment::create(['student_id' => $student->id, 'course_id' => $assignment->course_id, 'status' => 'active']);

        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $student->id,
            'github_repo_url'   => 'https://github.com/student/project',
            'submission_status' => 'queued',
            'submitted_at'      => now(),
            'is_late'           => false,
            'retry_count'       => 0,
        ]);

        $job = new GradeSubmissionJob($submission);
        $job->failed(new \RuntimeException("Cannot reach grader service"));

        $submission->refresh();
        $this->assertEquals('failed', $submission->submission_status);

        Notification::assertSentTo($student, GradingFailedNotification::class);
    }

    // ═════════════════════════════════════════════════════════════════════
    // RETRY TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_retry_failed_submission(): void
    {
        Queue::fake();

        $teacher    = $this->makeTeacher();
        $assignment = $this->makeGradableAssignment($teacher);
        $student    = $this->makeStudent();
        Enrollment::create(['student_id' => $student->id, 'course_id' => $assignment->course_id, 'status' => 'active']);

        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $student->id,
            'github_repo_url'   => 'https://github.com/student/project',
            'submission_status' => 'failed',
            'submitted_at'      => now(),
            'is_late'           => false,
            'retry_count'       => 1,
        ]);

        $this->actingAs($student)
             ->postJson("/api/submissions/{$submission->id}/retry")
             ->assertOk()
             ->assertJsonFragment(['message' => 'Grading retry queued.']);

        Queue::assertPushedOn('grading', GradeSubmissionJob::class);
        $submission->refresh();
        $this->assertEquals('queued', $submission->submission_status);
        $this->assertEquals(2, $submission->retry_count);
    }

    public function test_cannot_retry_graded_submission(): void
    {
        $teacher    = $this->makeTeacher();
        $assignment = $this->makeGradableAssignment($teacher);
        $student    = $this->makeStudent();
        Enrollment::create(['student_id' => $student->id, 'course_id' => $assignment->course_id, 'status' => 'active']);

        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $student->id,
            'github_repo_url'   => 'https://github.com/student/project',
            'submission_status' => 'graded',
            'submitted_at'      => now(),
            'is_late'           => false,
            'retry_count'       => 0,
        ]);

        $this->actingAs($student)
             ->postJson("/api/submissions/{$submission->id}/retry")
             ->assertUnprocessable();
    }

    // ═════════════════════════════════════════════════════════════════════
    // NOTIFICATION TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_read_notifications(): void
    {
        $student = $this->makeStudent();
        $student->notify(new \Illuminate\Notifications\DatabaseNotification());

        $this->actingAs($student)
             ->getJson('/api/notifications')
             ->assertOk()
             ->assertJsonStructure(['data', 'meta']);
    }

    public function test_student_can_mark_all_notifications_read(): void
    {
        $student = $this->makeStudent();

        $this->actingAs($student)
             ->patchJson('/api/notifications/read-all')
             ->assertOk()
             ->assertJsonFragment(['message' => 'All notifications marked as read.']);
    }
}
