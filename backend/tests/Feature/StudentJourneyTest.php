<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentJourneyTest extends TestCase
{
    use RefreshDatabase;

    // ═════════════════════════════════════════════════════════════════════
    // AUTH
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_register(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name'            => 'Youssef',
            'last_name'             => 'El Mansouri',
            'email'                 => 'youssef@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'student',
        ])->assertCreated()
          ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);

        $this->assertDatabaseHas('users', ['email' => 'youssef@test.com', 'role' => 'student']);
    }

    public function test_teacher_can_register(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name'            => 'Amina',
            'last_name'             => 'Tazi',
            'email'                 => 'amina@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'teacher',
        ])->assertCreated()
          ->assertJsonFragment(['role' => 'teacher']);
    }

    public function test_role_must_be_teacher_or_student(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name'            => 'Test',
            'last_name'             => 'User',
            'email'                 => 'test@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'admin',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['role']);
    }

    public function test_student_can_login(): void
    {
        // Factory creates users with Hash::make('password')
        $student = User::factory()->student()->create();

        $this->postJson('/api/auth/login', [
            'email'    => $student->email,
            'password' => 'password',
        ])->assertOk()
          ->assertJsonStructure(['token', 'user']);
    }

    public function test_invalid_credentials_rejected(): void
    {
        $student = User::factory()->student()->create();

        $this->postJson('/api/auth/login', [
            'email'    => $student->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    // ═════════════════════════════════════════════════════════════════════
    // COURSES
    // ═════════════════════════════════════════════════════════════════════

    public function test_teacher_can_create_course(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)
             ->postJson('/api/courses', [
                 'code'          => 'CS301',
                 'title'         => 'Data Structures',
                 'academic_year' => 2024,
                 'semester'      => 'Fall',
                 'credits'       => 3,
             ])->assertCreated()
               ->assertJsonFragment(['code' => 'CS301']);

        $this->assertDatabaseHas('courses', ['code' => 'CS301', 'instructor_id' => $teacher->id]);
    }

    public function test_student_cannot_create_course(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
             ->postJson('/api/courses', [
                 'code'          => 'CS999',
                 'title'         => 'Hacked Course',
                 'academic_year' => 2024,
                 'semester'      => 'Fall',
             ])->assertForbidden();
    }

    public function test_student_sees_only_active_courses(): void
    {
        $teacher = User::factory()->teacher()->create();
        Course::factory(2)->forInstructor($teacher)->active()->create();
        Course::factory()->forInstructor($teacher)->inactive()->create(); // hidden

        $student = User::factory()->student()->create();

        $res = $this->actingAs($student)->getJson('/api/courses');
        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_teacher_sees_only_own_courses(): void
    {
        $teacher1 = User::factory()->teacher()->create();
        $teacher2 = User::factory()->teacher()->create();
        Course::factory(2)->forInstructor($teacher1)->create();
        Course::factory()->forInstructor($teacher2)->create(); // not teacher1's

        $res = $this->actingAs($teacher1)->getJson('/api/courses');
        $this->assertCount(2, $res->json('data'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // ENROLLMENTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_enroll_in_course(): void
    {
        $teacher = User::factory()->teacher()->create();
        $course  = Course::factory()->forInstructor($teacher)->active()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($student)
             ->postJson('/api/enrollments', ['course_id' => $course->id])
             ->assertCreated()
             ->assertJsonFragment(['status' => 'active']);

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);
    }

    public function test_student_cannot_enroll_twice(): void
    {
        $teacher = User::factory()->teacher()->create();
        $course  = Course::factory()->forInstructor($teacher)->active()->create();
        $student = User::factory()->student()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->postJson('/api/enrollments', ['course_id' => $course->id])
             ->assertUnprocessable();
    }

    public function test_enrollment_respects_max_students(): void
    {
        $teacher = User::factory()->teacher()->create();
        $course  = Course::factory()->forInstructor($teacher)->active()->create([
            'max_students' => 1,
        ]);
        $student1 = User::factory()->student()->create();
        $student2 = User::factory()->student()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student1->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student2)
             ->postJson('/api/enrollments', ['course_id' => $course->id])
             ->assertUnprocessable();
    }

    // ═════════════════════════════════════════════════════════════════════
    // ASSIGNMENTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_teacher_can_create_assignment(): void
    {
        $teacher = User::factory()->teacher()->create();
        $course  = Course::factory()->forInstructor($teacher)->create();

        $this->actingAs($teacher)
             ->postJson('/api/assignments', [
                 'course_id'       => $course->id,
                 'title'           => 'Fibonacci in Python',
                 'assignment_type' => 'code',
                 'max_score'       => 100,
                 'due_date'        => now()->addDays(14)->toIso8601String(),
                 'language'        => 'python',
                 'is_published'    => true,
             ])->assertCreated()
               ->assertJsonFragment(['title' => 'Fibonacci in Python']);
    }

    public function test_teacher_can_add_phase2_test_cases(): void
    {
        $teacher = User::factory()->teacher()->create();
        $course  = Course::factory()->forInstructor($teacher)->create();

        $this->actingAs($teacher)
             ->postJson('/api/assignments', [
                 'course_id'       => $course->id,
                 'title'           => 'Sort Algorithm',
                 'assignment_type' => 'code',
                 'due_date'        => now()->addDays(7)->toIso8601String(),
                 'language'        => 'python',
                 'test_cases'      => [
                     ['id' => 'tc1', 'name' => 'Empty list', 'strategy' => 'output_contains', 'expected' => '[]', 'weight' => 50],
                     ['id' => 'tc2', 'name' => 'Sorted',     'strategy' => 'exit_zero',                            'weight' => 50],
                 ],
             ])->assertCreated();

        $this->assertNotNull(Assignment::where('title', 'Sort Algorithm')->first()->test_cases);
    }

    public function test_student_cannot_see_draft_assignments(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->draft()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->getJson("/api/assignments/{$assignment->id}")
             ->assertNotFound();
    }

    // ═════════════════════════════════════════════════════════════════════
    // SUBMISSIONS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_submit_assignment(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->upcoming()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/fibonacci',
                 'github_branch'   => 'main',
             ])->assertCreated()
               ->assertJsonFragment([
                   'submission_status' => 'pending',
                   'is_late'           => false,
               ]);
    }

    public function test_submission_is_marked_late(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->pastDueLateAllowed()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/late-submit',
             ])->assertCreated()
               ->assertJsonFragment(['is_late' => true]);
    }

    public function test_late_submission_rejected_when_not_allowed(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->pastDue()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/too-late',
             ])->assertUnprocessable();
    }

    public function test_unenrolled_student_cannot_submit(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->upcoming()->create();
        // intentionally NOT enrolling the student

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/hack',
             ])->assertForbidden();
    }

    public function test_student_cannot_submit_twice(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->upcoming()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $payload = [
            'assignment_id'   => $assignment->id,
            'github_repo_url' => 'https://github.com/student/project',
        ];

        $this->actingAs($student)->postJson('/api/submissions', $payload)->assertCreated();
        $this->actingAs($student)->postJson('/api/submissions', $payload)->assertUnprocessable();
    }

    public function test_invalid_github_url_rejected(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->upcoming()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://evil.com/hack/code',
             ])->assertUnprocessable()
               ->assertJsonValidationErrors(['github_repo_url']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // FACTORY STATES
    // ═════════════════════════════════════════════════════════════════════

    public function test_graded_submission_factory_state(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->published()->create();

        $submission = Submission::factory()->graded()->create([
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
        ]);

        $this->assertEquals('graded', $submission->submission_status);
        $this->assertNotNull($submission->auto_grade_score);
        $this->assertNotNull($submission->final_score);
        $this->assertGreaterThanOrEqual(30, (float) $submission->final_score);
    }

    public function test_pending_submission_factory_state(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->published()->create();

        $submission = Submission::factory()->pending()->create([
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
        ]);

        $this->assertEquals('pending', $submission->submission_status);
        $this->assertNull($submission->auto_grade_score);
        $this->assertNull($submission->final_score);
    }

    public function test_failed_submission_can_be_retried(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->autoGradable()->create();

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $submission = Submission::factory()->failed()->create([
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
        ]);

        $this->assertTrue($submission->canRetry());
        $this->assertTrue($submission->isFailed());
    }

    public function test_phase2_nullable_columns_are_null_in_phase1(): void
    {
        $teacher    = User::factory()->teacher()->create();
        $course     = Course::factory()->forInstructor($teacher)->create();
        $student    = User::factory()->student()->create();
        $assignment = Assignment::factory()->for($course)->published()->create([
            'test_cases'    => null,
            'docker_config' => null,
        ]);

        Enrollment::factory()->active()->create([
            'student_id' => $student->id,
            'course_id'  => $course->id,
        ]);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/project',
             ])->assertCreated();

        $submission = Submission::where('student_id', $student->id)->first();

        $this->assertNull($submission->auto_grade_score);
        $this->assertNull($submission->manual_grade_score);
        $this->assertNull($submission->final_score);
        $this->assertNull($submission->last_retry_at);
        $this->assertNull($assignment->test_cases);
        $this->assertNull($assignment->docker_config);
    }
}