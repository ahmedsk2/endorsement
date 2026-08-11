<?php

namespace Tests\Feature\Clinics;

use App\Models\Clinic;
use App\Models\ClinicAttendee;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Clinics\ClinicWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P1e Decision B. `App\Support\Clinics\ClinicWriter` is the ONE writer of `clinics` and
 * `clinic_attendees`, and it is the only thing standing between the child table and a row that
 * names neither a level nor a person, names both, or duplicates one — because finding 12 means the
 * DATABASE will not do it. A UNIQUE index over nullable columns enforces nothing on either SQLite
 * or MySQL 8.4 (NULLs compare distinct), which is the identical situation
 * `2026_08_14_120002`'s docblock already records for `person_levels`' overlap rule: the guarantee
 * lives in `App\Support\LevelAssignment`, which `PersonLevelsHaveOneWriterTest` proves is the only
 * writer. This is that shape, again, deliberately.
 *
 * CL-01's field set and CL-02's three modes. Attendance is a RULE here, never a resolved roster —
 * `ClinicRoster` (Task 3) answers "who is on this clinic on this date" by asking the master rota on
 * the day, so nothing in this file maintains a copy of the rota.
 */
class ClinicWriterTest extends TestCase
{
    use RefreshDatabase;

    private Unit $ward;

    protected function setUp(): void
    {
        parent::setUp();

        // `Unit` has NO factory (CLAUDE.md; `AssignmentIntegrityTest::setUp()` records why), and
        // `active` is NOT optional — the column defaults to FALSE, so a unit created without it
        // is retired on arrival. `clinic_owner` is likewise opt-in: owner Decision B (P1b) makes
        // WARD the clinic owner at QCH, but this test reads the COLUMN, never the code — a fifth
        // department that ticks the box gets clinics with no change here.
        $this->ward = Unit::create([
            'code' => 'CLW',
            'name' => 'Clinic Test Ward',
            'active' => true,
            'clinic_owner' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'name' => 'General Paediatrics',
            'weekday' => 2,
            'session' => 'AM',
        ], $overrides);
    }

    public function test_a_clinic_must_belong_to_a_clinic_owning_unit(): void
    {
        $picu = Unit::create(['code' => 'CLP', 'name' => 'Clinic Test PICU', 'active' => true, 'clinic_owner' => false]);

        $this->expectException(InvalidArgumentException::class);

        ClinicWriter::create($picu, $this->attributes());
    }

    public function test_a_clinic_on_a_retired_unit_is_refused(): void
    {
        $retired = Unit::create(['code' => 'CLR', 'name' => 'Clinic Test Retired', 'active' => false, 'clinic_owner' => true]);

        $this->expectException(InvalidArgumentException::class);

        ClinicWriter::create($retired, $this->attributes());
    }

    public function test_the_weekday_is_iso_and_zero_and_eight_are_refused(): void
    {
        // Monday is 1 and Sunday is 7 — the same numbering `Calendar::weekendDays()`,
        // `::weekStartIsoDay()` and `::weekdayColumns()` use. ZERO is the load-bearing case: it is
        // exactly what Carbon's `dayOfWeek` calls Sunday, so a writer that accepted it would be
        // the third numbering scheme this codebase keeps paying for.
        $monday = ClinicWriter::create($this->ward, $this->attributes(['weekday' => 1]));
        $sunday = ClinicWriter::create($this->ward, $this->attributes(['weekday' => 7, 'name' => 'Sunday clinic']));

        $this->assertSame(1, $monday->weekday);
        $this->assertSame(7, $sunday->weekday);

        foreach ([0, 8, -1] as $outOfRange) {
            try {
                ClinicWriter::create($this->ward, $this->attributes(['weekday' => $outOfRange]));
                $this->fail("weekday {$outOfRange} was accepted; ISO-8601 runs 1..7.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_session_must_be_one_of_the_offered_list(): void
    {
        // Read the constant rather than repeating ['AM','PM'] — the D9 shape: a predicate written
        // twice is two predicates, and the offer and the write-side rule must come from one place.
        foreach (array_keys(Clinic::SESSIONS) as $index => $session) {
            $clinic = ClinicWriter::create($this->ward, $this->attributes([
                'session' => $session,
                'name' => 'Session clinic '.$index,
            ]));

            $this->assertSame($session, $clinic->session);
        }

        $this->assertArrayNotHasKey('EV', Clinic::SESSIONS, 'This test assumes EV is not an offered session.');

        $this->expectException(InvalidArgumentException::class);

        ClinicWriter::create($this->ward, $this->attributes(['session' => 'EV']));
    }

    public function test_levels_mode_refuses_a_named_person_row_and_named_mode_refuses_a_level_row(): void
    {
        $clinic = ClinicWriter::create($this->ward, $this->attributes());
        $level = Level::factory()->create();
        $person = Person::factory()->create();

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['person_id' => $person->getKey()]]);
            $this->fail('A person row was accepted in levels mode.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_NAMED, [['level_id' => $level->getKey()]]);
            $this->fail('A level row was accepted in named mode.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // A row naming BOTH, and a row naming NEITHER, are the two shapes a UNIQUE index over
        // nullable columns cannot refuse on either engine. They are refused here or nowhere.
        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [
                ['level_id' => $level->getKey(), 'person_id' => $person->getKey()],
            ]);
            $this->fail('A row naming both a level and a person was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['level_id' => null]]);
            $this->fail('A row naming neither a level nor a person was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, ClinicAttendee::query()->count(),
            'A refused set must write nothing at all — refusals happen before the transaction.');
    }

    public function test_rotators_mode_holds_no_attendee_rows_and_switching_to_it_clears_them(): void
    {
        $clinic = ClinicWriter::create($this->ward, $this->attributes());
        $level = Level::factory()->create();

        ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['level_id' => $level->getKey()]]);
        $this->assertSame(1, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count());

        ClinicWriter::setAttendees($clinic, Clinic::MODE_ROTATORS);

        $this->assertSame(Clinic::MODE_ROTATORS, $clinic->fresh()->attendee_mode);
        $this->assertSame(0, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count(),
            'CL-02\'s default mode is "everyone the rota has on the unit" — a leftover refinement row '
            .'would be a rule nothing reads and everything could start reading.');

        // Handing rotators mode a set is a contradiction, not a request to ignore the set.
        $this->expectException(InvalidArgumentException::class);

        ClinicWriter::setAttendees($clinic, Clinic::MODE_ROTATORS, [['level_id' => $level->getKey()]]);
    }

    public function test_the_same_level_cannot_be_attached_twice_and_nor_can_the_same_person(): void
    {
        $clinic = ClinicWriter::create($this->ward, $this->attributes());
        $level = Level::factory()->create();
        $person = Person::factory()->create();

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [
                ['level_id' => $level->getKey()],
                ['level_id' => $level->getKey()],
            ]);
            $this->fail('The same level was attached twice.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_NAMED, [
                ['person_id' => $person->getKey()],
                ['person_id' => $person->getKey()],
            ]);
            $this->fail('The same person was attached twice.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, ClinicAttendee::query()->count());
    }

    public function test_setting_attendees_replaces_the_set_rather_than_appending(): void
    {
        // `RotaAssignment::split()`'s replace semantics, and for its reason: a re-run of a seeder
        // (or a second save of the same form) must converge, not duplicate.
        $clinic = ClinicWriter::create($this->ward, $this->attributes());
        $r1 = Level::factory()->create(['code' => 'CR1']);
        $r2 = Level::factory()->create(['code' => 'CR2']);
        $r3 = Level::factory()->create(['code' => 'CR3']);

        ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [
            ['level_id' => $r1->getKey()],
            ['level_id' => $r2->getKey()],
        ]);

        ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [
            ['level_id' => $r2->getKey()],
            ['level_id' => $r3->getKey()],
        ]);

        $attached = ClinicAttendee::query()
            ->where('clinic_id', $clinic->getKey())
            ->pluck('level_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$r2->getKey(), $r3->getKey()], $attached);
    }

    public function test_two_clinics_may_share_a_unit_weekday_and_session(): void
    {
        // Deliberate: two rooms, one session. This pins the ABSENCE of a unique index on
        // (unit_id, weekday, session) so nobody adds one later on the reasonable-sounding grounds
        // that a clinic is identified by when it runs. It is not.
        $first = ClinicWriter::create($this->ward, $this->attributes(['name' => 'General clinic, room 1']));
        $second = ClinicWriter::create($this->ward, $this->attributes(['name' => 'General clinic, room 2']));

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, Clinic::query()
            ->where('unit_id', $this->ward->getKey())
            ->where('weekday', 2)
            ->where('session', 'AM')
            ->count());
    }

    public function test_the_name_location_and_note_are_stored_verbatim_and_never_purified(): void
    {
        // Invariant 11: these three are PLAIN TEXT, exactly like `handovers.extra_fields`, and are
        // never purified server-side. Escaping is the renderer's job — `{{ }}` / `:value`, never
        // `v-html`. A writer that stripped tags here would silently corrupt a clinic literally
        // called "ENT <> Audiology".
        $raw = 'ENT <b>&</b> Audiology <script>alert(1)</script>';

        $clinic = ClinicWriter::create($this->ward, $this->attributes([
            'name' => $raw,
            'location' => 'Level 2 <East>',
            'note' => "Bring the <hearing> booth key & the 'blue' folder",
        ]));

        $stored = $clinic->fresh();

        $this->assertSame($raw, $stored->name);
        $this->assertSame('Level 2 <East>', $stored->location);
        $this->assertSame("Bring the <hearing> booth key & the 'blue' folder", $stored->note);
    }

    public function test_an_update_may_move_a_clinic_but_never_onto_a_unit_that_does_not_own_clinics(): void
    {
        $clinic = ClinicWriter::create($this->ward, $this->attributes());
        $other = Unit::create(['code' => 'CLO', 'name' => 'Other clinic owner', 'active' => true, 'clinic_owner' => true]);
        $picu = Unit::create(['code' => 'CLP', 'name' => 'Clinic Test PICU', 'active' => true, 'clinic_owner' => false]);

        $moved = ClinicWriter::update($clinic, $other, $this->attributes(['weekday' => 4, 'session' => 'PM']));

        $this->assertSame($other->getKey(), $moved->unit_id);
        $this->assertSame(4, $moved->weekday);
        $this->assertSame('PM', $moved->session);

        $this->expectException(InvalidArgumentException::class);

        ClinicWriter::update($clinic, $picu, $this->attributes());
    }

    public function test_a_clinic_is_deactivated_never_deleted_and_cannot_be_revived_onto_a_retired_unit(): void
    {
        // UN-04's "hides forward, never deletes history", applied to a clinic. There is no
        // destroy() on the controller (Task 4) and none here.
        $clinic = ClinicWriter::create($this->ward, $this->attributes());

        ClinicWriter::setActive($clinic, false);
        $this->assertFalse($clinic->fresh()->active);
        $this->assertSame(1, Clinic::query()->count(), 'Deactivating must not remove the row.');

        $this->ward->forceFill(['active' => false])->save();

        $this->expectException(InvalidArgumentException::class);

        ClinicWriter::setActive($clinic, true);
    }

    public function test_an_attendee_row_naming_a_level_or_person_that_does_not_exist_is_refused(): void
    {
        $clinic = ClinicWriter::create($this->ward, $this->attributes());

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['level_id' => 909090]]);
            $this->fail('A level id that does not exist was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            ClinicWriter::setAttendees($clinic, Clinic::MODE_NAMED, [['person_id' => 909090]]);
            $this->fail('A person id that does not exist was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_institution_id_is_provenance_supplied_by_the_caller(): void
    {
        // D11: provenance and in-instance grouping, never a query filter and never part of a key.
        // The writer takes it from the ACTOR (the `$request->user()?->institution_id` precedent
        // Level/Period/Holiday already set) rather than reading `Institution::current()` itself.
        $institution = Institution::create(['name' => 'Clinic Test Hospital', 'code' => 'CLH', 'active' => true]);

        $clinic = ClinicWriter::create($this->ward, $this->attributes(), $institution->getKey());

        $this->assertSame($institution->getKey(), $clinic->institution_id);
        $this->assertNull(ClinicWriter::create($this->ward, $this->attributes(['name' => 'No provenance']))->institution_id);
    }
}
