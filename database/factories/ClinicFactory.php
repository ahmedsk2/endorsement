<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Clinic>
 *
 * NOT a production writer — `App\Support\Clinics\ClinicWriter` is the only one (P1e Decision B),
 * and this factory is named on `ClinicWritersAreSingularTest::ALLOW_LIST` as the same carve-out
 * `PersonLevelsHaveOneWriterTest` makes for `PersonLevelFactory` and `RotaWritersAreSingularTest`
 * for `MasterRotaAssignmentFactory`: a factory populating fixture rows for OTHER tests is not the
 * production integrity surface those guards close.
 *
 * IT WRITES `clinics` ONLY, NEVER `clinic_attendees`, and that is the point of the carve-out being
 * safe. The three constraints no database engine can express — exactly one of `level_id`/
 * `person_id`, no duplicate within a clinic, and a set homogeneous with `attendee_mode` — are all
 * constraints on the CHILD table, so none is reachable through this file. A clinic built here is
 * therefore always in the one mode that needs no rows: `rotators`.
 *
 * `Unit` has NO factory of its own (CLAUDE.md; `Unit::create()` defaults `active` to FALSE), so
 * this builds one directly with `active` AND `clinic_owner` set explicitly — the same discipline
 * `MasterRotaAssignmentFactory` follows. `clinic_owner` is not decoration: `ClinicWriter` refuses a
 * clinic on a unit that does not own clinics, so a fixture unit without it produces rows the writer
 * would never have written.
 *
 * `weekday` is ISO-8601, Monday = 1 … Sunday = 7 — the same numbering `Calendar::weekendDays()`
 * uses, and never Carbon's `dayOfWeek`.
 */
class ClinicFactory extends Factory
{
    protected $model = Clinic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'unit_id' => Unit::create([
                'code' => 'C'.Str::upper(Str::random(5)),
                'name' => 'Factory clinic unit '.Str::random(4),
                'active' => true,
                'clinic_owner' => true,
            ])->id,
            'name' => 'Factory clinic '.Str::random(4),
            'weekday' => fake()->numberBetween(1, 7),
            'session' => fake()->randomElement(array_keys(Clinic::SESSIONS)),
            'location' => null,
            'note' => null,
            'attendee_mode' => Clinic::MODE_ROTATORS,
            'active' => true,
        ];
    }

    /** A clinic that has stopped running. Deactivated, never deleted (UN-04). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
