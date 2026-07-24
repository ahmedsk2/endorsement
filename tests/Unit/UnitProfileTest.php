<?php

namespace Tests\Unit;

use App\Support\UnitProfile;
use PHPUnit\Framework\TestCase;

/**
 * The UnitProfile is the SINGLE source of per-unit variation (spec §3). These tests pin the
 * whole table — every surface (sheet columns, sign-off fields, print schema, hue) reads from
 * here, so a drift in this class is a drift everywhere.
 */
class UnitProfileTest extends TestCase
{
    public function test_all_four_units_have_profiles(): void
    {
        $this->assertSame(['PICU', 'NICU', 'SCBU', 'WARD'], UnitProfile::codes());

        foreach (UnitProfile::codes() as $code) {
            $this->assertInstanceOf(UnitProfile::class, UnitProfile::for($code));
        }
    }

    public function test_lookup_is_case_insensitive_and_unknown_codes_throw(): void
    {
        $this->assertSame('WARD', UnitProfile::for('ward')->code);

        $this->expectException(\InvalidArgumentException::class);
        UnitProfile::for('ICU');
    }

    public function test_picu_shows_no_extra_identity_columns(): void
    {
        $p = UnitProfile::for('PICU');

        $this->assertSame([], $p->extraRowFields);
        $this->assertSame('Bed', $p->bedLabel);
        $this->assertTrue($p->consultantPair);
        $this->assertSame('channel-bar-picu', $p->barClass);
        $this->assertSame('New events', $p->printNarrativeLabel);
        $this->assertSame('Plan Of Care', $p->printPlanLabel);
    }

    public function test_nicu_and_scbu_add_dob(): void
    {
        foreach (['NICU', 'SCBU'] as $code) {
            $p = UnitProfile::for($code);

            $this->assertSame(['dob'], $p->extraRowFields, $code);
            $this->assertSame('Bed', $p->bedLabel, $code);
            $this->assertTrue($p->consultantPair, $code);
            $this->assertSame('To be followed', $p->printNarrativeLabel, $code);
            $this->assertSame('Plan Of Care', $p->printPlanLabel, $code);
        }

        $this->assertSame('channel-bar-nicu', UnitProfile::for('NICU')->barClass);
        $this->assertSame('channel-bar-scbu', UnitProfile::for('SCBU')->barClass);
    }

    public function test_ward_has_age_and_sub_unit_and_a_single_oncall_consultant(): void
    {
        $p = UnitProfile::for('WARD');

        $this->assertSame(['age', 'ward_unit'], $p->extraRowFields);
        $this->assertSame('Room', $p->bedLabel);
        // Ruling 5 — WARD has ONE consultant field, labelled "Consultant Oncall",
        // stored in consultant_by_*; the receiving field is hidden.
        $this->assertFalse($p->consultantPair);
        $this->assertSame('Consultant Oncall', $p->consultantByLabel);
        $this->assertSame('channel-bar-ward', $p->barClass);
        $this->assertSame('Management', $p->printPlanLabel);
        $this->assertSame('To be followed', $p->printNarrativeLabel);
    }

    public function test_non_ward_units_label_the_consultant_pair(): void
    {
        foreach (['PICU', 'NICU', 'SCBU'] as $code) {
            $p = UnitProfile::for($code);
            $this->assertSame('Consultant covering', $p->consultantByLabel, $code);
        }
    }

    /** The client receives the profile as a plain array via Inertia. */
    public function test_to_array_carries_the_client_contract(): void
    {
        $arr = UnitProfile::for('WARD')->toArray();

        $this->assertSame('WARD', $arr['code']);
        $this->assertSame(['age', 'ward_unit'], $arr['extra_row_fields']);
        $this->assertSame('Room', $arr['bed_label']);
        $this->assertFalse($arr['consultant_pair']);
        $this->assertSame('Consultant Oncall', $arr['consultant_by_label']);
        $this->assertSame('channel-bar-ward', $arr['bar_class']);
        $this->assertSame('To be followed', $arr['narrative_label']);
    }
}
