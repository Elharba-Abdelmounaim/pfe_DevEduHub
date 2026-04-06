<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    // Shared hashed password — avoids bcrypt cost on every factory call
    private static ?string $hashedPassword = null;

    public function definition(): array
    {
        return [
            // UUID is handled by HasUuids trait + gen_random_uuid() DB default,
            // but Faker still needs a deterministic value for in-memory test seeding
            'first_name'               => fake()->firstName(),
            'last_name'                => fake()->lastName(),
            'email'                    => fake()->unique()->safeEmail(),
            'password_hash'            => static::$hashedPassword ??= Hash::make('password'),
            'role'                     => 'student',         // override with states
            'avatar_url'               => fake()->boolean(40)
                                            ? fake()->imageUrl(200, 200, 'people')
                                            : null,
            'phone'                    => fake()->boolean(60)
                                            ? fake()->phoneNumber()
                                            : null,
            'bio'                      => fake()->boolean(50)
                                            ? fake()->sentences(2, true)
                                            : null,
            'github_username'          => fake()->boolean(70)
                                            ? fake()->userName()
                                            : null,
            'github_token_encrypted'   => null,             // Phase 2 — never seeded
            'is_active'                => true,
            'is_verified'              => true,
            'email_verification_token' => null,
            'password_reset_token'     => null,
            'password_reset_expires'   => null,
            'last_login'               => fake()->boolean(80)
                                            ? fake()->dateTimeBetween('-30 days', 'now')
                                            : null,
        ];
    }

    // ── States ────────────────────────────────────────────────────────────────

    /** User is a teacher / instructor */
    public function teacher(): static
    {
        return $this->state(fn(array $attributes) => [
            'role'       => 'teacher',
            'bio'        => fake()->sentences(3, true),
            'is_verified' => true,
        ]);
    }

    /** User is a student */
    public function student(): static
    {
        return $this->state(fn(array $attributes) => [
            'role' => 'student',
        ]);
    }

    /** Unverified account (email not confirmed) */
    public function unverified(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_verified'              => false,
            'email_verification_token' => Str::random(64),
        ]);
    }

    /** Deactivated account */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active'  => false,
            'last_login' => fake()->dateTimeBetween('-6 months', '-2 months'),
        ]);
    }

    /** User has linked a GitHub account */
    public function withGithub(): static
    {
        return $this->state(fn(array $attributes) => [
            'github_username' => fake()->userName(),
        ]);
    }
}
