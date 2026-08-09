<?php

namespace Database\Factories;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MasterRotaAssignment>
 *
 * NOT a production writer — `App\Support\Rota\RotaAssignment` will be the only one once it
 * lands, the same carve-out `PersonLevelsHaveOneWriterTest` makes for `PersonLevelFactory`.
 *
 * `Unit` has NO factory of its own (CLAUDE.md; `Unit::create()` defaults `active` to FALSE), so
 * this factory builds one directly with `active` set explicitly — the same discipline
 * `AssignmentIntegrityTest::setUp()` follows by hand.
 *
 * The default span equals its period's own bounds — the degenerate whole-period split (Decision
 * B) — so `MasterRotaAssignment::factory()->create()` satisfies the model's containment guard
 * with no caller-supplied dates. The period is created EAGERLY (not via a lazy
 * `Period::factory()` relation) specifically so its real bounds are available here to compute
 * `starts_on`/`ends_on` from — the model's `saving` guard refuses a span that does not lie
 * inside its period, and a lazily-resolved relation's bounds are not known at definition() time.
 */
class MasterRotaAssignmentFactory extends Factory
{
    protected $model = MasterRotaAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $period = Period::factory()->create();

        return [
            'institution_id' => null,
            'person_id' => Person::factory(),
            'period_id' => $period->id,
            'unit_id' => Unit::create([
                'code' => 'F'.Str::upper(Str::random(5)),
                'name' => 'Factory unit '.Str::random(4),
                'active' => true,
            ])->id,
            'starts_on' => $period->starts_on->format('Y-m-d'),
            'ends_on' => $period->ends_on->format('Y-m-d'),
        ];
    }
}
