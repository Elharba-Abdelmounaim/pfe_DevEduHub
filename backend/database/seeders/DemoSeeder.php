<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Demo Data
    |--------------------------------------------------------------------------
    | Credentials (all use "password" as the password):
    |
    | Teacher:
    |   email: teacher@deveduhub.com
    |   role:  teacher
    |
    | Students:
    |   email: student1@deveduhub.com
    |   email: student2@deveduhub.com
    |
    */

    public function run(): void
    {
        $this->command->info('Seeding demo data...');

        // ── 1. Teacher ────────────────────────────────────────────────────────
        $teacher = User::factory()->teacher()->create([
            'first_name'  => 'Amina',
            'last_name'   => 'Tazi',
            'email'       => 'teacher@deveduhub.com',
            'password_hash' => Hash::make('password'),
            'github_username' => 'amina-tazi',
            'bio'         => 'Senior lecturer in Computer Science with 10+ years of experience.',
        ]);

        $this->command->line("  ✓ Teacher: {$teacher->email}");

        // ── 2. Students ───────────────────────────────────────────────────────
        $student1 = User::factory()->student()->create([
            'first_name'  => 'Youssef',
            'last_name'   => 'El Mansouri',
            'email'       => 'student1@deveduhub.com',
            'password_hash' => Hash::make('password'),
            'github_username' => 'youssef-elmansouri',
        ]);

        $student2 = User::factory()->student()->create([
            'first_name'  => 'Fatima',
            'last_name'   => 'Zahra',
            'email'       => 'student2@deveduhub.com',
            'password_hash' => Hash::make('password'),
            'github_username' => 'fatima-zahra',
        ]);

        $this->command->line("  ✓ Student 1: {$student1->email}");
        $this->command->line("  ✓ Student 2: {$student2->email}");

        // ── 3. Course ─────────────────────────────────────────────────────────
        $course = Course::factory()->forInstructor($teacher)->currentYear()->create([
            'code'        => 'CS301',
            'title'       => 'Python Programming Fundamentals',
            'description' => 'A hands-on course covering Python 3 from basics to intermediate topics including data structures, OOP, and testing.',
            'credits'     => 3,
            'max_students'=> 30,
            'is_active'   => true,
        ]);

        $this->command->line("  ✓ Course: [{$course->code}] {$course->title}");

        // ── 4. Enrollments ────────────────────────────────────────────────────
        $enrollment1 = Enrollment::create([
            'student_id'      => $student1->id,
            'course_id'       => $course->id,
            'enrollment_date' => now()->subDays(30),
            'status'          => 'active',
        ]);

        $enrollment2 = Enrollment::create([
            'student_id'      => $student2->id,
            'course_id'       => $course->id,
            'enrollment_date' => now()->subDays(28),
            'status'          => 'active',
        ]);

        $this->command->line("  ✓ Enrolled {$student1->first_name} in {$course->code}");
        $this->command->line("  ✓ Enrolled {$student2->first_name} in {$course->code}");

        // ── 5. Assignments ────────────────────────────────────────────────────
        $assignment1 = Assignment::factory()
            ->for($course)
            ->autoGradable('python')
            ->create([
                'title'       => 'Assignment 1: Fibonacci Sequence',
                'description' => 'Write a Python function fib(n) that returns the nth Fibonacci number. Your solution must handle n=0 to n=50 efficiently.',
                'max_score'   => 100,
                'due_date'    => now()->subDays(7),    // already past due
                'late_submission_allowed' => false,
                'is_published' => true,
            ]);

        $assignment2 = Assignment::factory()
            ->for($course)
            ->published()
            ->create([
                'title'       => 'Assignment 2: Sorting Algorithms',
                'description' => 'Implement Bubble Sort, Merge Sort, and Quick Sort in Python. Compare their performance on different input sizes.',
                'max_score'   => 100,
                'due_date'    => now()->addDays(14),   // upcoming
                'late_submission_allowed' => true,
                'late_penalty_percentage' => 10,
                'language'    => 'python',
                'is_published' => true,
            ]);

        $this->command->line("  ✓ Assignment 1: {$assignment1->title} (past due, auto-gradable)");
        $this->command->line("  ✓ Assignment 2: {$assignment2->title} (upcoming)");

        // ── 6. Submissions ────────────────────────────────────────────────────

        // Student 1 — submitted Assignment 1, already graded
        $submission1 = Submission::factory()
            ->graded()
            ->create([
                'assignment_id'    => $assignment1->id,
                'student_id'       => $student1->id,
                'github_repo_url'  => 'https://github.com/youssef-elmansouri/fibonacci-solution',
                'github_commit_sha'=> 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
                'github_branch'    => 'main',
                'submitted_at'     => now()->subDays(10),
                'is_late'          => false,
                'auto_grade_score' => 87.50,
                'final_score'      => 87.50,
                'teacher_feedback' => 'Great use of memoisation! Consider edge cases for n=0.',
                'student_notes'    => 'Implemented with dynamic programming for O(n) time complexity.',
            ]);

        // Student 2 — submitted Assignment 1, teacher gave manual override
        $submission2 = Submission::factory()
            ->manuallyGraded()
            ->create([
                'assignment_id'     => $assignment1->id,
                'student_id'        => $student2->id,
                'github_repo_url'   => 'https://github.com/fatima-zahra/fibonacci-hw',
                'github_commit_sha' => 'b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3',
                'github_branch'     => 'solution',
                'submitted_at'      => now()->subDays(8),
                'is_late'           => false,
                'auto_grade_score'  => 70.00,
                'manual_grade_score'=> 75.00,
                'final_score'       => 75.00,
                'teacher_feedback'  => 'Correct recursive approach but missing memoisation. Manual score adjusted.',
            ]);

        // Student 1 — submitted Assignment 2 (pending, not yet graded)
        $submission3 = Submission::factory()
            ->pending()
            ->create([
                'assignment_id'   => $assignment2->id,
                'student_id'      => $student1->id,
                'github_repo_url' => 'https://github.com/youssef-elmansouri/sorting-algos',
                'github_branch'   => 'main',
                'submitted_at'    => now()->subHours(2),
                'is_late'         => false,
                'student_notes'   => 'All three algorithms implemented with unit tests.',
            ]);

        $this->command->line("  ✓ Submission: {$student1->first_name} → {$assignment1->title} (graded: 87.5)");
        $this->command->line("  ✓ Submission: {$student2->first_name} → {$assignment1->title} (manual: 75.0)");
        $this->command->line("  ✓ Submission: {$student1->first_name} → {$assignment2->title} (pending)");

        $this->command->newLine();
        $this->command->info('Demo seeding complete.');
        $this->command->table(
            ['Role', 'Name', 'Email', 'Password'],
            [
                ['Teacher', $teacher->first_name . ' ' . $teacher->last_name, $teacher->email, 'password'],
                ['Student', $student1->first_name . ' ' . $student1->last_name, $student1->email, 'password'],
                ['Student', $student2->first_name . ' ' . $student2->last_name, $student2->email, 'password'],
            ]
        );
    }
}
