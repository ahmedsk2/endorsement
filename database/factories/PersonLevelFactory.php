<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonLevel>
 *
 * NOT a production writer — `App\Support\LevelAssignment` is the only one
 * (`tests/Feature/Build/PersonLevelsHaveOneWriterTest.php` allow-lists this file for exactly that
 * reason, the same way `ContactFieldsAreProjectedOnceTest` excuses `database/factories/`
 * generally). This factory exists so tests can seed history directly, the same role
 * `PersonFactory`/`LevelFactory` play for their own tables.
 *
 * `effective_from` is a FIXED date, never `fake()->date()`: a random start date makes an
 * inclusive-bounds failure reproduce one run in thirty rather than every run (P1c finding 4's own
 * reasoning, restated here because this is the first factory for the table).
 */
class PersonLevelFactory extends Factory
{
    protected $model = PersonLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'level_id' => Level::factory(),
            'effective_from' => '2026-07-01',
            'effective_to' => null,
            'promotion_batch_id' => null,
            'reason' => null,
            'created_by' => null,
        ];
    }
}
