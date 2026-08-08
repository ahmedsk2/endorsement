<?php

namespace Database\Factories;

use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
{
    protected $model = Period::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Spaced four weeks apart by position so factory-created rows never collide with one
        // another under Period's overlap guard unless a test deliberately asks them to.
        $position = fake()->unique()->numberBetween(1, 500);
        $start = CarbonImmutable::parse('2026-07-01')->addWeeks(($position - 1) * 4);
        $end = $start->addWeeks(4)->subDay();

        return [
            'institution_id' => null,
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => $position,
            'label' => "Block {$position}",
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
        ];
    }

    public function month(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => Period::MONTH]);
    }
}
