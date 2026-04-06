<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    private static array $titles = [
        'Hello World — Getting Started',
        'Fibonacci Sequence Implementation',
        'Binary Search Tree Operations',
        'REST API with CRUD endpoints',
        'Sorting Algorithms Benchmark',
        'Simple Calculator Application',
        'File I/O and CSV Parser',
        'Linked List Implementation',
        'Graph Traversal (BFS & DFS)',
        'Mini Web Scraper',
        'Unit Testing with pytest',
        'Database Query Optimisation',
        'Authentication System',
        'Real-Time Chat with WebSockets',
        'Docker Container Setup',
    ];

    /** Realistic test_cases jsonb structure for Phase 2 */
    private function buildTestCases(string $language = 'python'): array
    {
        return [
            [
                'id'              => 'tc1',
                'name'            => 'Basic functionality',
                'strategy'        => 'exit_zero',
                'weight'          => 30,
                'hint'            => 'Make sure your program exits with code 0',
            ],
            [
                'id'              => 'tc2',
                'name'            => 'Produces expected output',
                'strategy'        => 'has_output',
                'weight'          => 30,
                'hint'            => 'Your program must print at least one line to stdout',
            ],
            [
                'id'              => 'tc3',
                'name'            => 'No runtime errors',
                'strategy'        => 'no_stderr',
                'weight'          => 20,
                'hint'            => 'Fix any exceptions or warnings printed to stderr',
            ],
            [
                'id'              => 'tc4',
                'name'            => 'Completes within time limit',
                'strategy'        => 'no_timeout',
                'weight'          => 20,
                'hint'            => 'Optimise your solution to avoid timeouts',
            ],
        ];
    }

    /** Realistic docker_config jsonb structure for Phase 2 */
    private function buildDockerConfig(string $language = 'python'): array
    {
        $imageMap = [
            'python' => 'python:3.11-slim',
            'node'   => 'node:18-alpine',
            'java'   => 'eclipse-temurin:17-jdk-alpine',
        ];

        return [
            'image'        => $imageMap[$language] ?? 'python:3.11-slim',
            'memory_limit' => '128m',
            'cpu_limit'    => '0.5',
            'timeout'      => 30,
            'network'      => 'none',
        ];
    }

    public function definition(): array
    {
        $language  = fake()->randomElement(['python', 'node', 'java']);
        $dueDate   = fake()->dateTimeBetween('+7 days', '+60 days');

        return [
            'course_id'               => Course::factory(),
            'title'                   => fake()->randomElement(static::$titles),
            'description'             => fake()->paragraphs(3, true),
            'assignment_type'         => fake()->randomElement(['code', 'project']),
            'max_score'               => fake()->randomElement([50, 75, 100]),
            'due_date'                => $dueDate,
            'late_submission_allowed' => fake()->boolean(30),
            'late_penalty_percentage' => fake()->randomElement([0, 5, 10, 20]),
            'language'                => $language,
            'test_cases'              => null,      // null until published with grader config
            'docker_config'           => null,
            'requirements'            => null,
            'is_published'            => false,     // draft by default
        ];
    }

    // ── States ────────────────────────────────────────────────────────────────

    /** Published assignment — visible to students */
    public function published(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_published' => true,
        ]);
    }

    /** Draft assignment — not yet visible to students */
    public function draft(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_published' => false,
        ]);
    }

    /**
     * Auto-gradable — has test_cases + docker_config configured.
     * Activates the Phase 2 grading pipeline when a submission is made.
     */
    public function autoGradable(string $language = 'python'): static
    {
        return $this->state(fn(array $attributes) => [
            'is_published'  => true,
            'language'      => $language,
            'test_cases'    => $this->buildTestCases($language),
            'docker_config' => $this->buildDockerConfig($language),
        ]);
    }

    /** Assignment with a past due date (supports is_late testing) */
    public function pastDue(): static
    {
        return $this->state(fn(array $attributes) => [
            'due_date'                => fake()->dateTimeBetween('-30 days', '-1 day'),
            'late_submission_allowed' => false,
        ]);
    }

    /** Past due but late submissions accepted */
    public function pastDueLateAllowed(): static
    {
        return $this->state(fn(array $attributes) => [
            'due_date'                => fake()->dateTimeBetween('-30 days', '-1 day'),
            'late_submission_allowed' => true,
            'late_penalty_percentage' => 10,
        ]);
    }

    /** Due date far in the future */
    public function upcoming(): static
    {
        return $this->state(fn(array $attributes) => [
            'due_date'     => fake()->dateTimeBetween('+14 days', '+90 days'),
            'is_published' => true,
        ]);
    }
}
