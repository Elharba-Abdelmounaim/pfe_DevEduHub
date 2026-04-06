<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExtraStudentsSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Extra Students Seeder
    |--------------------------------------------------------------------------
    | Creates additional realistic data to make the dev environment useful:
    |   - 2 extra teachers with their own courses
    |   - 10 extra students
    |   - Enrollments across courses
    |   - A mix of graded, pending, and failed submissions
    */

    public function run(): void
    {
        $this->command->info('Seeding extra students and courses...');

        // ── Extra teachers + their courses ────────────────────────────────────
        $extraTeachers = User::factory(2)->teacher()->create();

        foreach ($extraTeachers as $teacher) {
            // Each extra teacher gets 1–2 courses
            $courses = Course::factory(rand(1, 2))
                ->forInstructor($teacher)
                ->currentYear()
                ->create();

            foreach ($courses as $course) {
                // Each course gets 2–3 assignments
                Assignment::factory(rand(2, 3))
                    ->for($course)
                    ->published()
                    ->create();

                // 1 draft assignment per course
                Assignment::factory()
                    ->for($course)
                    ->draft()
                    ->create();
            }
        }

        // ── 10 extra students ─────────────────────────────────────────────────
        $students = User::factory(10)->student()->create();

        // Grab the demo course (CS301) to enroll extra students
        $demoCourse     = Course::where('code', 'CS301')->first();
        $allCourses     = Course::where('is_active', true)->get();
        $allAssignments = Assignment::where('is_published', true)->get();

        foreach ($students as $student) {
            // Enroll each extra student in 1–3 active courses
            $coursesToEnroll = $allCourses->random(min(rand(1, 3), $allCourses->count()));

            foreach ($coursesToEnroll as $course) {
                // Skip if already enrolled (handles demo course overlap)
                $alreadyEnrolled = Enrollment::where([
                    'student_id' => $student->id,
                    'course_id'  => $course->id,
                ])->exists();

                if ($alreadyEnrolled) {
                    continue;
                }

                Enrollment::factory()
                    ->active()
                    ->create([
                        'student_id' => $student->id,
                        'course_id'  => $course->id,
                    ]);

                // For each enrolled course, submit 0–2 published assignments
                $courseAssignments = $allAssignments
                    ->where('course_id', $course->id)
                    ->random(min(rand(0, 2), $allAssignments->where('course_id', $course->id)->count()));

                foreach ($courseAssignments as $assignment) {
                    // Pick a random submission state weighted towards graded
                    $state = fake()->randomElement([
                        'graded',  'graded',  'graded',   // 3/6 chance graded
                        'pending', 'pending',              // 2/6 chance pending
                        'failed',                          // 1/6 chance failed
                    ]);

                    Submission::factory()
                        ->{$state}()
                        ->create([
                            'assignment_id' => $assignment->id,
                            'student_id'    => $student->id,
                            'is_late'       => $assignment->isPastDue() ? fake()->boolean(30) : false,
                        ]);
                }
            }
        }

        $this->command->line('  ✓ 2 extra teachers with courses');
        $this->command->line('  ✓ 10 extra students with enrollments');
        $this->command->line('  ✓ Mixed graded/pending/failed submissions');
        $this->command->info('Extra seeding complete.');
    }
}
