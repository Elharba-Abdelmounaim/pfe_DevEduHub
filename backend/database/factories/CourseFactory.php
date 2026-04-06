<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    private static array $courseTitles = [
        'Introduction to Python Programming',
        'Data Structures & Algorithms',
        'Web Development with Laravel',
        'Database Design with PostgreSQL',
        'Operating Systems Fundamentals',
        'Object-Oriented Programming in Java',
        'Computer Networks & Protocols',
        'Software Engineering Principles',
        'Machine Learning Foundations',
        'Mobile App Development',
        'DevOps & Continuous Integration',
        'Cybersecurity Essentials',
        'Cloud Computing with AWS',
        'Algorithms & Complexity',
        'Functional Programming with Haskell',
    ];

    private static array $courseCodes = [
        'CS101', 'CS201', 'CS301', 'CS401',
        'WD101', 'WD201', 'DB101', 'DB201',
        'SE101', 'SE201', 'ML101', 'NET201',
        'OS301', 'SEC401', 'CC401',
    ];

    public function definition(): array
    {
        static $codeIndex = 0;

        return [
            'instructor_id' => User::factory()->teacher(),
            'code'          => static::$courseCodes[$codeIndex++ % count(static::$courseCodes)]
                               . '-' . fake()->unique()->numberBetween(1, 999),
            'title'         => fake()->randomElement(static::$courseTitles),
            'description'   => fake()->paragraphs(2, true),
            'academic_year' => fake()->numberBetween(2023, 2025),
            'semester'      => fake()->randomElement(['Fall', 'Spring', 'Summer']),
            'credits'       => fake()->randomElement([2, 3, 4]),
            'max_students'  => fake()->randomElement([20, 25, 30, 40, 50]),
            'is_active'     => true,
        ];
    }

    // ── States ────────────────────────────────────────────────────────────────

    /** Course is active and visible to students */
    public function active(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => true,
        ]);
    }

    /** Course is archived / inactive */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }

    /** Course belongs to a specific instructor */
    public function forInstructor(User $teacher): static
    {
        return $this->state(fn(array $attributes) => [
            'instructor_id' => $teacher->id,
        ]);
    }

    /** Course is in the current academic year */
    public function currentYear(): static
    {
        return $this->state(fn(array $attributes) => [
            'academic_year' => now()->year,
            'semester'      => now()->month <= 6 ? 'Spring' : 'Fall',
        ]);
    }
}
