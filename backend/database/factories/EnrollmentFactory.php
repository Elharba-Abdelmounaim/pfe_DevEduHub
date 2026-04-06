<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'student_id'      => User::factory()->student(),
            'course_id'       => Course::factory(),
            'enrollment_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'status'          => 'active',
            'final_grade'     => null,
        ];
    }

    // ── States ────────────────────────────────────────────────────────────────

    /** Active enrollment */
    public function active(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'      => 'active',
            'final_grade' => null,
        ]);
    }

    /** Student dropped the course */
    public function dropped(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'      => 'dropped',
            'final_grade' => null,
        ]);
    }

    /** Course completed with a final grade */
    public function completed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'      => 'completed',
            'final_grade' => fake()->randomFloat(2, 40, 100),
        ]);
    }

    /** Passing grade (>= 50) */
    public function passed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'      => 'completed',
            'final_grade' => fake()->randomFloat(2, 50, 100),
        ]);
    }

    /** Failing grade (< 50) */
    public function failed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'      => 'completed',
            'final_grade' => fake()->randomFloat(2, 0, 49.99),
        ]);
    }
}
