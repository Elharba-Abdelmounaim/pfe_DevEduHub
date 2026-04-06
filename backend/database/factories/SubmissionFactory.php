<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    private static array $githubRepos = [
        'fibonacci-python',
        'sorting-algorithms',
        'rest-api-laravel',
        'binary-search-tree',
        'linked-list-impl',
        'hello-world-solution',
        'graph-traversal',
        'calculator-app',
        'file-parser',
        'unit-testing-demo',
    ];

    /** Generate a realistic fake GitHub commit SHA */
    private function fakeSha(): string
    {
        return bin2hex(random_bytes(20));    // 40-char hex
    }

    /** Generate a realistic GitHub username/repo URL */
    private function fakeRepoUrl(): string
    {
        $username = fake()->userName();
        $repo     = fake()->randomElement(static::$githubRepos);

        return "https://github.com/{$username}/{$repo}";
    }

    public function definition(): array
    {
        return [
            'assignment_id'     => Assignment::factory()->published(),
            'student_id'        => User::factory()->student(),
            'github_repo_url'   => $this->fakeRepoUrl(),
            'github_commit_sha' => $this->fakeSha(),
            'github_branch'     => fake()->randomElement(['main', 'master', 'solution', 'submission']),
            'submission_type'   => 'github',
            'file_path'         => null,
            'submission_status' => 'pending',
            'submitted_at'      => fake()->dateTimeBetween('-30 days', 'now'),
            'is_late'           => false,

            // Phase 2 scoring — null in Phase 1
            'auto_grade_score'  => null,
            'manual_grade_score'=> null,
            'final_score'       => null,
            'teacher_feedback'  => null,
            'student_notes'     => fake()->boolean(40)
                                     ? fake()->sentence()
                                     : null,
            'retry_count'       => 0,
            'last_retry_at'     => null,
        ];
    }

    // ── States ────────────────────────────────────────────────────────────────

    /** Submission just created — waiting to be picked up by queue */
    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'submission_status'  => 'pending',
            'auto_grade_score'   => null,
            'manual_grade_score' => null,
            'final_score'        => null,
            'teacher_feedback'   => null,
        ]);
    }

    /** Submission is in the Redis queue, waiting for a worker */
    public function queued(): static
    {
        return $this->state(fn(array $attributes) => [
            'submission_status' => 'queued',
            'auto_grade_score'  => null,
            'final_score'       => null,
        ]);
    }

    /** Worker has picked up the job and is running Docker */
    public function grading(): static
    {
        return $this->state(fn(array $attributes) => [
            'submission_status' => 'grading',
            'auto_grade_score'  => null,
            'final_score'       => null,
        ]);
    }

    /**
     * Graded by auto-grader — has auto_grade_score and final_score.
     * This is the expected end-state after Phase 2 pipeline completes.
     */
    public function graded(): static
    {
        $score = fake()->randomFloat(2, 30, 100);

        return $this->state(fn(array $attributes) => [
            'submission_status' => 'graded',
            'auto_grade_score'  => $score,
            'manual_grade_score'=> null,
            'final_score'       => $score,
            'teacher_feedback'  => fake()->boolean(60)
                                     ? fake()->sentences(2, true)
                                     : null,
        ]);
    }

    /** Graded with a perfect score */
    public function perfect(): static
    {
        return $this->state(fn(array $attributes) => [
            'submission_status' => 'graded',
            'auto_grade_score'  => 100.00,
            'manual_grade_score'=> null,
            'final_score'       => 100.00,
            'teacher_feedback'  => 'Excellent work! All tests passed.',
        ]);
    }

    /** Graded with a failing score (below 50) */
    public function failing(): static
    {
        $score = fake()->randomFloat(2, 0, 49.99);

        return $this->state(fn(array $attributes) => [
            'submission_status' => 'graded',
            'auto_grade_score'  => $score,
            'manual_grade_score'=> null,
            'final_score'       => $score,
            'teacher_feedback'  => 'Several tests failed. Please review the requirements.',
        ]);
    }

    /**
     * Manually graded by teacher — teacher has overridden the auto score.
     */
    public function manuallyGraded(): static
    {
        $autoScore   = fake()->randomFloat(2, 30, 90);
        $manualScore = fake()->randomFloat(2, max(0, $autoScore - 20), min(100, $autoScore + 20));

        return $this->state(fn(array $attributes) => [
            'submission_status'  => 'graded',
            'auto_grade_score'   => $autoScore,
            'manual_grade_score' => $manualScore,
            'final_score'        => $manualScore,     // teacher override wins
            'teacher_feedback'   => fake()->sentences(3, true),
        ]);
    }

    /** Grading permanently failed after all retries */
    public function failed(): static
    {
        return $this->state(fn(array $attributes) => [
            'submission_status' => 'failed',
            'auto_grade_score'  => null,
            'final_score'       => null,
            'retry_count'       => fake()->numberBetween(1, 3),
            'last_retry_at'     => fake()->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    /** Submission was late */
    public function late(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_late'      => true,
            'submitted_at' => fake()->dateTimeBetween('-10 days', '-1 day'),
        ]);
    }

    /** Submission has been retried at least once */
    public function retried(): static
    {
        $count = fake()->numberBetween(1, 2);

        return $this->state(fn(array $attributes) => [
            'retry_count'   => $count,
            'last_retry_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ]);
    }
}
