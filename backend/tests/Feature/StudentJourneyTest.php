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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeTeacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    private function makeStudent(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function makeCourse(User $teacher, array $attrs = []): Course
    {
        return Course::factory()->for($teacher, 'instructor')->create($attrs);
    }

    private function makeAssignment(Course $course, array $attrs = []): Assignment
    {
        return Assignment::factory()->for($course)->create(array_merge([
            'is_published' => true,
            'due_date'     => now()->addDays(7),
        ], $attrs));
    }

    private function enroll(User $student, Course $course): Enrollment
    {
        return Enrollment::create([
            'student_id'      => $student->id,
            'course_id'       => $course->id,
            'enrollment_date' => now(),
            'status'          => 'active',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // AUTH TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_register(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'first_name'            => 'Youssef',
            'last_name'             => 'El Mansouri',
            'email'                 => 'youssef@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'student',
        ]);

        $res->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);

        $this->assertDatabaseHas('users', ['email' => 'youssef@test.com', 'role' => 'student']);
    }

    public function test_teacher_can_register(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'first_name'            => 'Amina',
            'last_name'             => 'Tazi',
            'email'                 => 'amina@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'teacher',
        ]);

        $res->assertCreated()
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
        $student = $this->makeStudent();

        $this->postJson('/api/auth/login', [
            'email'    => $student->email,
            'password' => 'password',          // Factory default
        ])->assertOk()
          ->assertJsonStructure(['token', 'user']);
    }

    public function test_invalid_credentials_rejected(): void
    {
        $student = $this->makeStudent();

        $this->postJson('/api/auth/login', [
            'email'    => $student->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    // ═════════════════════════════════════════════════════════════════════
    // COURSE TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_teacher_can_create_course(): void
    {
        $teacher = $this->makeTeacher();

        $this->actingAs($teacher)
             ->postJson('/api/courses', [
                 'code'          => 'CS301',
                 'title'         => 'Data Structures',
                 'academic_year' => 2024,
                 'semester'      => 'Fall',
                 'credits'       => 3,
             ])
             ->assertCreated()
             ->assertJsonFragment(['code' => 'CS301']);

        $this->assertDatabaseHas('courses', ['code' => 'CS301', 'instructor_id' => $teacher->id]);
    }

    public function test_student_cannot_create_course(): void
    {
        $student = $this->makeStudent();

        $this->actingAs($student)
             ->postJson('/api/courses', [
                 'code'          => 'CS999',
                 'title'         => 'Hack',
                 'academic_year' => 2024,
                 'semester'      => 'Fall',
             ])
             ->assertForbidden();
    }

    public function test_student_can_view_active_courses(): void
    {
        $teacher = $this->makeTeacher();
        $this->makeCourse($teacher, ['is_active' => true]);
        $this->makeCourse($teacher, ['is_active' => true]);
        $this->makeCourse($teacher, ['is_active' => false]);  // hidden

        $student = $this->makeStudent();

        $res = $this->actingAs($student)->getJson('/api/courses');
        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_teacher_only_sees_own_courses(): void
    {
        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();
        $this->makeCourse($teacher1);
        $this->makeCourse($teacher1);
        $this->makeCourse($teacher2);   // not teacher1's

        $res = $this->actingAs($teacher1)->getJson('/api/courses');
        $this->assertCount(2, $res->json('data'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // ENROLLMENT TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_enroll_in_course(): void
    {
        $teacher = $this->makeTeacher();
        $course  = $this->makeCourse($teacher);
        $student = $this->makeStudent();

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
        $teacher = $this->makeTeacher();
        $course  = $this->makeCourse($teacher);
        $student = $this->makeStudent();
        $this->enroll($student, $course);

        $this->actingAs($student)
             ->postJson('/api/enrollments', ['course_id' => $course->id])
             ->assertUnprocessable();
    }

    public function test_enrollment_respects_max_students(): void
    {
        $teacher = $this->makeTeacher();
        $course  = $this->makeCourse($teacher, ['max_students' => 1]);
        $s1      = $this->makeStudent();
        $s2      = $this->makeStudent();
        $this->enroll($s1, $course);

        $this->actingAs($s2)
             ->postJson('/api/enrollments', ['course_id' => $course->id])
             ->assertUnprocessable();
    }

    // ═════════════════════════════════════════════════════════════════════
    // ASSIGNMENT TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_teacher_can_create_assignment(): void
    {
        $teacher = $this->makeTeacher();
        $course  = $this->makeCourse($teacher);

        $this->actingAs($teacher)
             ->postJson('/api/assignments', [
                 'course_id'       => $course->id,
                 'title'           => 'Fibonacci in Python',
                 'assignment_type' => 'code',
                 'max_score'       => 100,
                 'due_date'        => now()->addDays(14)->toIso8601String(),
                 'language'        => 'python',
                 'is_published'    => true,
             ])
             ->assertCreated()
             ->assertJsonFragment(['title' => 'Fibonacci in Python']);
    }

    public function test_teacher_can_add_phase2_test_cases(): void
    {
        $teacher = $this->makeTeacher();
        $course  = $this->makeCourse($teacher);

        $res = $this->actingAs($teacher)
             ->postJson('/api/assignments', [
                 'course_id'       => $course->id,
                 'title'           => 'Sort Algorithm',
                 'assignment_type' => 'code',
                 'due_date'        => now()->addDays(7)->toIso8601String(),
                 'language'        => 'python',
                 'test_cases'      => [
                     ['id' => 'tc1', 'name' => 'Empty list', 'input' => '[]', 'expected_output' => '[]', 'weight' => 50],
                     ['id' => 'tc2', 'name' => 'Sorted list', 'input' => '[3,1,2]', 'expected_output' => '[1,2,3]', 'weight' => 50],
                 ],
             ])
             ->assertCreated();

        $this->assertDatabaseHas('assignments', ['title' => 'Sort Algorithm']);
        $this->assertNotNull(Assignment::where('title', 'Sort Algorithm')->first()->test_cases);
    }

    public function test_student_cannot_see_unpublished_assignments(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course, ['is_published' => false]);
        $this->enroll($student, $course);

        $this->actingAs($student)
             ->getJson("/api/assignments/{$assignment->id}")
             ->assertNotFound();
    }

    // ═════════════════════════════════════════════════════════════════════
    // SUBMISSION TESTS
    // ═════════════════════════════════════════════════════════════════════

    public function test_student_can_submit_assignment(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        $this->enroll($student, $course);

        $res = $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/fibonacci',
                 'github_branch'   => 'main',
             ]);

        $res->assertCreated()
            ->assertJsonFragment(['submission_status' => 'pending'])
            ->assertJsonFragment(['is_late' => false]);

        $this->assertDatabaseHas('submissions', [
            'student_id'        => $student->id,
            'assignment_id'     => $assignment->id,
            'submission_status' => 'pending',
            'is_late'           => false,
        ]);
    }

    public function test_submission_is_marked_late_after_due_date(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course, [
            'due_date'                => now()->subDay(),
            'late_submission_allowed' => true,
        ]);
        $this->enroll($student, $course);

        $res = $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/late-submission',
             ]);

        $res->assertCreated()
            ->assertJsonFragment(['is_late' => true]);
    }

    public function test_late_submission_rejected_when_not_allowed(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course, [
            'due_date'                => now()->subDay(),
            'late_submission_allowed' => false,
        ]);
        $this->enroll($student, $course);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/too-late',
             ])
             ->assertUnprocessable()
             ->assertJsonFragment(['message' => 'This assignment is past its due date and does not accept late submissions.']);
    }

    public function test_unenrolled_student_cannot_submit(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        // intentionally NOT enrolling the student

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/hack',
             ])
             ->assertForbidden();
    }

    public function test_student_cannot_submit_twice(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        $this->enroll($student, $course);

        $payload = [
            'assignment_id'   => $assignment->id,
            'github_repo_url' => 'https://github.com/student/project',
        ];

        $this->actingAs($student)->postJson('/api/submissions', $payload)->assertCreated();
        $this->actingAs($student)->postJson('/api/submissions', $payload)->assertUnprocessable();
    }

    public function test_invalid_github_url_rejected(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        $this->enroll($student, $course);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://evil.com/hack/code',
             ])
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['github_repo_url']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // TEACHER GRADING (Phase 2 hook test)
    // ═════════════════════════════════════════════════════════════════════

    public function test_teacher_can_add_manual_grade(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        $this->enroll($student, $course);

        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $student->id,
            'github_repo_url'   => 'https://github.com/student/project',
            'submission_status' => 'pending',
            'submitted_at'      => now(),
            'is_late'           => false,
            'retry_count'       => 0,
        ]);

        $this->actingAs($teacher)
             ->patchJson("/api/submissions/{$submission->id}", [
                 'manual_grade_score' => 85.5,
                 'teacher_feedback'   => 'Great work, minor style issues.',
             ])
             ->assertOk()
             ->assertJsonFragment([
                 'manual_grade_score' => '85.50',
                 'submission_status'  => 'graded',
             ]);
    }

    public function test_student_cannot_grade_submission(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        $this->enroll($student, $course);

        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $student->id,
            'github_repo_url'   => 'https://github.com/student/project',
            'submission_status' => 'pending',
            'submitted_at'      => now(),
            'is_late'           => false,
            'retry_count'       => 0,
        ]);

        $this->actingAs($student)
             ->patchJson("/api/submissions/{$submission->id}", [
                 'manual_grade_score' => 100,
             ])
             ->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════
    // PHASE 2 READINESS TEST
    // ═════════════════════════════════════════════════════════════════════

    public function test_phase2_nullable_columns_dont_break_phase1(): void
    {
        $teacher    = $this->makeTeacher();
        $course     = $this->makeCourse($teacher);
        $student    = $this->makeStudent();
        $assignment = $this->makeAssignment($course);
        $this->enroll($student, $course);

        $this->actingAs($student)
             ->postJson('/api/submissions', [
                 'assignment_id'   => $assignment->id,
                 'github_repo_url' => 'https://github.com/student/project',
             ])
             ->assertCreated();

        $submission = Submission::where('student_id', $student->id)->first();

        // Phase 2 nullable fields must be null in Phase 1
        $this->assertNull($submission->auto_grade_score);
        $this->assertNull($submission->manual_grade_score);
        $this->assertNull($submission->final_score);
        $this->assertNull($submission->last_retry_at);

        // Phase 2 jsonb fields on assignment must be null
        $this->assertNull($assignment->test_cases);
        $this->assertNull($assignment->docker_config);
    }
}
