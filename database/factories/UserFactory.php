<?php

namespace Database\Factories;

use App\Models\Person;
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

            // Every account belongs to a person (P0c). This key is LAST on purpose: factory
            // closures are resolved against the already-expanded attribute array, so the person
            // inherits whatever the caller overrode — `full_name`, `position`, `member_email`,
            // `institution_id` — with no per-test change anywhere.
            'person_id' => fn (array $attributes) => Person::factory()->create([
                'full_name' => $attributes['full_name'] ?? fake()->name(),
                'position' => $attributes['position'] ?? 4,
                'email' => $attributes['member_email'] ?? null,
                'institution_id' => $attributes['institution_id'] ?? null,
                // The ACCOUNT's kill switch is not the PERSON's. `->inactive()` means "cannot log
                // in", which is what every existing test that uses it means; the person stays
                // nameable, which is exactly the distinction P0c introduces.
                'active' => true,
            ])->id,
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
