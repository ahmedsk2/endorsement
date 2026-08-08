<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 *
 * A person created through this factory ALONE is roster-only — there is no `->rosterOnly()` state
 * because there is nothing to switch off. An account is `User::factory()->for($person, 'person')`,
 * or simply `User::factory()`, which creates its own linked person.
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'full_name' => fake()->name(),
            'short_name' => null,
            'position' => 4,               // Resident (position 1 / Nurse is retired)
            'email' => fake()->unique()->safeEmail(),
            'external' => false,
            'active' => true,
        ];
    }

    /** On the roster but no longer nameable — a leaver, or someone off the department. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
