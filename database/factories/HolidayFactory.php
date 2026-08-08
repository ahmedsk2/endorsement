<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'name' => fake()->unique()->words(2, true).' Holiday',
            'calendar' => Holiday::GREGORIAN,
            'month' => fake()->numberBetween(1, 12),
            'day' => fake()->numberBetween(1, 28),
            'year' => null,
            'duration_days' => 1,
            'equity_tracked' => true,
            'active' => true,
        ];
    }

    public function hijri(): static
    {
        return $this->state(fn (array $attributes) => ['calendar' => Holiday::HIJRI]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
