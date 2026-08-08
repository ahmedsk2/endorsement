<?php

namespace Tests\Unit;

use App\Models\Unit;
use App\Support\UnitProfile;
use Tests\TestCase;

/**
 * UnitProfile is the SHAPE of per-unit variation (spec §3); since P0a the VALUES live on the
 * `units` row (design §6.1). `fromUnit()` is the real construction path — these tests pin the
 * mapping from row to value object, plus the `toArray()` client contract Vue depends on, all
 * against UNSAVED `new Unit([...])` models. This file stays database-free (extends
 * `Tests\TestCase` only for the Eloquent casts machinery — no `RefreshDatabase`, no seeding).
 *
 * The deprecated `codes()`/`for()` DB-backed shims need a real database and live in
 * `tests/Feature/Units/UnitProfileShimTest.php` instead.
 */
class UnitProfileTest extends TestCase
{
    public function test_from_unit_maps_every_column_onto_the_value_object(): void
    {
        $unit = new Unit([
            'code' => 'WARD',
            'name' => 'Pediatric Ward',
            'extra_row_fields' => ['age', 'ward_unit'],
            'bed_label' => 'Room',
            'consultant_pair' => false,
            'consultant_by_label' => 'Consultant Oncall',
            'bar_class' => 'channel-bar-ward',
            'print_plan_label' => 'Management',
            'print_narrative_label' => 'To be followed',
        ]);

        $p = UnitProfile::fromUnit($unit);

        $this->assertSame('WARD', $p->code);
        $this->assertSame(['age', 'ward_unit'], $p->extraRowFields);
        $this->assertSame('Room', $p->bedLabel);
        $this->assertFalse($p->consultantPair);
        $this->assertSame('Consultant Oncall', $p->consultantByLabel);
        $this->assertSame('channel-bar-ward', $p->barClass);
        $this->assertSame('Management', $p->printPlanLabel);
        $this->assertSame('To be followed', $p->printNarrativeLabel);
    }

    public function test_from_unit_gives_usable_defaults_for_a_bare_unit(): void
    {
        $unit = new Unit(['code' => 'CARD', 'name' => 'Cardiology']);

        $p = UnitProfile::fromUnit($unit);

        $this->assertSame('CARD', $p->code);
        $this->assertSame([], $p->extraRowFields);
        $this->assertSame('Bed', $p->bedLabel);
        $this->assertTrue($p->consultantPair);
        $this->assertSame('Consultant covering', $p->consultantByLabel);
        $this->assertSame('channel-bar-card', $p->barClass);
        $this->assertSame('Plan Of Care', $p->printPlanLabel);
        $this->assertSame('To be followed', $p->printNarrativeLabel);
    }

    public function test_from_unit_upper_cases_the_code(): void
    {
        $unit = new Unit(['code' => 'ward']);

        $this->assertSame('WARD', UnitProfile::fromUnit($unit)->code);
    }

    /** The client receives the profile as a plain array via Inertia. */
    public function test_to_array_carries_the_client_contract(): void
    {
        $unit = new Unit([
            'code' => 'WARD',
            'extra_row_fields' => ['age', 'ward_unit'],
            'bed_label' => 'Room',
            'consultant_pair' => false,
            'consultant_by_label' => 'Consultant Oncall',
            'bar_class' => 'channel-bar-ward',
            'print_plan_label' => 'Management',
            'print_narrative_label' => 'To be followed',
        ]);

        $arr = UnitProfile::fromUnit($unit)->toArray();

        $this->assertSame('WARD', $arr['code']);
        $this->assertSame(['age', 'ward_unit'], $arr['extra_row_fields']);
        $this->assertSame('Room', $arr['bed_label']);
        $this->assertFalse($arr['consultant_pair']);
        $this->assertSame('Consultant Oncall', $arr['consultant_by_label']);
        $this->assertSame('channel-bar-ward', $arr['bar_class']);
        $this->assertSame('Management', $arr['plan_label']);
        $this->assertSame('To be followed', $arr['narrative_label']);
    }
}
