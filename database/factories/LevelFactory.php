<?php

namespace Database\Factories;

use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    protected $model = Level::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'code' => strtoupper(fake()->unique()->lexify('L??')),
            'name' => fake()->words(2, true),
            'display_order' => 1000,
            'active' => true,
        ];
    }

    /** No longer offered for new assignment, but existing history still resolves through it. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
