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
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'position' => 4, // Resident (position 1/Nurse is retired)
            'full_name' => fake()->name(),
            'member_name' => fake()->unique()->userName(),
            'member_email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
            'remember_token' => Str::random(10),
            // An account in NORMAL USE, which is what almost every test means by "a user".
            // Left null, RequireSetup would redirect every request in the suite to /setup
            // and 150 tests would be asserting against the onboarding page.
            //
            // A genuinely new account is the deliberate case: state it with
            // ->create(['setup_completed_at' => null]), as FirstLoginSetupTest does.
            'setup_completed_at' => now(),
        ];
    }

    /**
     * Indicate that the user is inactive (awaiting activation).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    /**
     * Indicate that the user is an administrator (position 0).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => 0,
        ]);
    }
}
