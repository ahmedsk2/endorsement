<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\Vacation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacation>
 *
 * NOT a production writer — `App\Support\Rota\VacationBooking` is the only one once it lands, the
 * same carve-out `PersonLevelsHaveOneWriterTest` makes for `PersonLevelFactory`.
 */
class VacationFactory extends Factory
{
    protected $model = Vacation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'person_id' => Person::factory(),
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-14',
            'granularity' => Vacation::GRANULARITY_DATE,
            'source' => Vacation::SOURCE_MANUAL,
        ];
    }
}
