<?php

namespace Tests\Feature\Clinics;

use App\Models\Clinic;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\Clinics\ClinicRoster;
use App\Support\Clinics\ClinicWriter;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1e Decision B's READ-TIME half. `clinic_attendees` holds the RULE; who actually turns up is
 * resolved from the master rota on the day it is asked for, and never stored.
 *
 * The property every case below is really testing is that MOVING THE ROTA MOVES THE CLINIC with no
 * write anywhere — which is exactly what a snapshot could not do, because a clinic is a weekly
 * recurrence with no date of its own and a snapshot would be a snapshot as of what day?
 *
 * `Level::factory()->create(['code' => 'R1'])` collides with `ReferenceSeeder`'s seeded ladder
 * (P1c Task 3 / Task 7's recorded trap), so every level here uses a `XC`-prefixed code — the same
 * discipline `RotaGridTest` records for its own `XG` prefix.
 */
class ClinicRosterTest extends TestCase
{
    use RefreshDatabase;

    private Unit $ward;

    private Unit $picu;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        // The institution row and the capability catalog: `test_no_contact_field_reaches_the_
        // projection` needs a real `people.manage` holder and a real department setting to be
        // the most permissive combination rather than a nominal one.
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        // `Unit` has NO factory (CLAUDE.md), and `active` is not optional — the column defaults to
        // FALSE, so a unit created without it is retired on arrival. `clinic_owner` is likewise
        // opt-in, and `ClinicWriter` refuses a clinic on a unit without it.
        $this->ward = Unit::create(['code' => 'CRW', 'name' => 'Roster Ward', 'active' => true, 'clinic_owner' => true]);
        $this->picu = Unit::create(['code' => 'CRP', 'name' => 'Roster PICU', 'active' => true, 'clinic_owner' => false]);

        $this->period = Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);
    }

    private function clinic(array $overrides = []): Clinic
    {
        return ClinicWriter::create($this->ward, array_merge([
            'name' => 'General Paediatrics',
            'weekday' => 2,
            'session' => 'AM',
        ], $overrides));
    }

    /** @param list<array<string, mixed>> $roster */
    private function idsIn(array $roster): array
    {
        return array_map(fn (array $row): int => (int) $row['id'], $roster);
    }

    /** @param list<array<string, mixed>> $roster */
    private function rowFor(array $roster, Person $person): ?array
    {
        foreach ($roster as $row) {
            if ((int) $row['id'] === (int) $person->getKey()) {
                return $row;
            }
        }

        return null;
    }

    public function test_rotators_mode_returns_everyone_the_rota_has_on_the_unit_that_day(): void
    {
        $onWard = Person::factory()->create(['full_name' => 'Aisha Roster']);
        $elsewhere = Person::factory()->create(['full_name' => 'Bilal Roster']);

        RotaAssignment::set($onWard, $this->period, $this->ward);
        RotaAssignment::set($elsewhere, $this->period, $this->picu);

        $roster = ClinicRoster::forDate($this->clinic(), '2026-07-14');

        $this->assertSame([$onWard->getKey()], $this->idsIn($roster));
        $this->assertSame(ClinicRoster::VIA_ROTATION, $roster[0]['via']);
        $this->assertFalse($roster[0]['stale']);
    }

    public function test_a_person_whose_span_ends_the_day_before_is_not_on_it(): void
    {
        $person = Person::factory()->create();

        // Ward until 14 July inclusive, then PICU. BOTH BOUNDS INCLUSIVE — the idiom
        // `Period::contains()` and `Person::levelAt()` already share.
        RotaAssignment::split($person, $this->period, [
            ['unit_id' => $this->ward->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->picu->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ]);

        $clinic = $this->clinic();

        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($clinic, '2026-07-14')));
        $this->assertSame([], ClinicRoster::forDate($clinic, '2026-07-15'));

        // And the first day of the span, for the other bound.
        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($clinic, '2026-07-01')));
    }

    public function test_a_split_period_puts_the_person_on_the_clinic_only_for_the_unit_half(): void
    {
        $person = Person::factory()->create();

        RotaAssignment::split($person, $this->period, [
            ['unit_id' => $this->picu->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-09'],
            ['unit_id' => $this->ward->getKey(), 'starts_on' => '2026-07-10', 'ends_on' => '2026-07-28'],
        ]);

        $clinic = $this->clinic();

        $this->assertSame([], ClinicRoster::forDate($clinic, '2026-07-07'));
        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($clinic, '2026-07-21')));
    }

    public function test_levels_mode_uses_the_level_held_on_that_date_not_the_current_one(): void
    {
        $junior = Level::factory()->create(['code' => 'XC1', 'display_order' => 10]);
        $senior = Level::factory()->create(['code' => 'XC2', 'display_order' => 20]);

        $person = Person::factory()->create();
        RotaAssignment::set($person, $this->period, $this->ward);

        LevelAssignment::assign($person, $junior, '2026-07-01');
        LevelAssignment::assign($person, $senior, '2026-07-15');

        $juniorClinic = $this->clinic();
        ClinicWriter::setAttendees($juniorClinic, Clinic::MODE_LEVELS, [['level_id' => $junior->getKey()]]);

        // The case a naive implementation gets wrong: `levelAt()` with no date, or the person's
        // CURRENT level, would answer "senior" on both dates and drop them from the earlier one.
        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($juniorClinic, '2026-07-08')));
        $this->assertSame([], ClinicRoster::forDate($juniorClinic, '2026-07-22'));

        $seniorClinic = $this->clinic(['name' => 'Senior clinic']);
        ClinicWriter::setAttendees($seniorClinic, Clinic::MODE_LEVELS, [['level_id' => $senior->getKey()]]);

        $this->assertSame([], ClinicRoster::forDate($seniorClinic, '2026-07-08'));
        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($seniorClinic, '2026-07-22')));
    }

    public function test_named_mode_ignores_the_rota_entirely(): void
    {
        // PE-03's external consultant: attends the clinic, rotates nowhere, has no span at all.
        $consultant = Person::factory()->create(['full_name' => 'Zara External', 'position' => 3, 'external' => true]);
        $rotator = Person::factory()->create(['full_name' => 'Amir Rotator']);

        RotaAssignment::set($rotator, $this->period, $this->ward);

        $clinic = $this->clinic();
        ClinicWriter::setAttendees($clinic, Clinic::MODE_NAMED, [['person_id' => $consultant->getKey()]]);

        $roster = ClinicRoster::forDate($clinic, '2026-07-14');

        $this->assertSame([$consultant->getKey()], $this->idsIn($roster));
        $this->assertSame(ClinicRoster::VIA_NAMED, $roster[0]['via']);
    }

    public function test_a_person_on_leave_is_still_returned_and_carries_no_availability_field(): void
    {
        $person = Person::factory()->create();
        RotaAssignment::set($person, $this->period, $this->ward);
        VacationBooking::book($person, '2026-07-13', '2026-07-17', Vacation::GRANULARITY_DATE);

        $roster = ClinicRoster::forDate($this->clinic(), '2026-07-14');

        $this->assertSame([$person->getKey()], $this->idsIn($roster));

        // CL-04 is P3. This resolver subtracts no leave and answers no question about who can
        // cover — pinned as BEHAVIOUR here, and at source level by `ClinicHooksTest` (Task 6).
        // Asserting the WHOLE key set, not a handful of absences, is what stops a future field
        // whose name nobody thought to list here.
        $this->assertSame([
            'id', 'full_name', 'short_name', 'position', 'external', 'active', 'retired',
            'has_account', 'joined_at', 'via', 'stale',
        ], array_keys($roster[0]));
    }

    public function test_no_contact_field_reaches_the_projection(): void
    {
        $person = Person::factory()->create([
            'phone' => '+966500000000',
            'notes' => 'A supervisor wrote this.',
            'constraints' => 'No nights.',
        ]);

        // The absence must not be vacuous: the row really does carry all four.
        $this->assertNotNull($person->fresh()->phone);

        RotaAssignment::set($person, $this->period, $this->ward);

        // THE MOST PERMISSIVE COMBINATION THIS SYSTEM CAN PRODUCE, and it is the one that
        // exposes the defect: `PersonPolicy::viewContact()` is `people.manage OR
        // membersMaySeeContact()`, so both branches are true at once for an administrator on a
        // department that has opted members in. P1d-2 found the live disclosure on the SECOND of
        // those — a `people.manage` holder on the DEFAULT setting — so both are set here.
        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);
        $this->actingAs(User::factory()->create(['position' => 0]));

        $roster = ClinicRoster::forDate($this->clinic(), '2026-07-14');

        $this->assertSame([$person->getKey()], $this->idsIn($roster));

        foreach (['email', 'phone', 'notes', 'constraints'] as $withheld) {
            $this->assertArrayNotHasKey($withheld, $roster[0],
                "the clinic roster projected [{$withheld}] for an administrator on contact_visibility=members");
        }
    }

    public function test_a_retired_person_still_holding_a_span_is_reported_stale_rather_than_attending(): void
    {
        $present = Person::factory()->create(['full_name' => 'Aaa Present']);
        $departed = Person::factory()->create(['full_name' => 'Bbb Departed']);
        $deleted = Person::factory()->create(['full_name' => 'Ccc Deleted']);

        foreach ([$present, $departed, $deleted] as $person) {
            RotaAssignment::set($person, $this->period, $this->ward);
        }

        $departed->forceFill(['active' => false])->save();
        $deleted->delete();

        $roster = ClinicRoster::forDate($this->clinic(), '2026-07-14');

        // A departed colleague on a clinic list reads as cover that is not there — and dropping
        // them silently is worse, because the span really is still on the rota and somebody has
        // to clear it. P1d-2 Decision D's shape: visible, flagged, not counted as attending.
        $this->assertSame(
            [$present->getKey(), $departed->getKey(), $deleted->getKey()],
            $this->idsIn($roster)
        );

        $this->assertFalse($this->rowFor($roster, $present)['stale']);
        $this->assertTrue($this->rowFor($roster, $departed)['stale']);
        $this->assertTrue($this->rowFor($roster, $deleted)['stale']);
    }

    public function test_an_inactive_clinic_still_resolves(): void
    {
        $person = Person::factory()->create();
        RotaAssignment::set($person, $this->period, $this->ward);

        $clinic = ClinicWriter::setActive($this->clinic(), false);

        // Resolution is not authorization: the map filters, the resolver answers.
        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($clinic, '2026-07-14')));
    }

    public function test_a_clinic_whose_unit_has_since_been_retired_still_resolves(): void
    {
        $person = Person::factory()->create();
        RotaAssignment::set($person, $this->period, $this->ward);

        $clinic = $this->clinic();

        // `ClinicWriter` refuses to CREATE or REVIVE a clinic on a retired unit, but a unit may be
        // retired under a clinic that already exists. Same rule as above: the resolver answers the
        // question it was asked; deciding whether the clinic belongs on a map is the map's job.
        $this->ward->forceFill(['active' => false])->save();

        $this->assertSame([$person->getKey()], $this->idsIn(ClinicRoster::forDate($clinic, '2026-07-14')));
    }

    public function test_a_date_that_is_not_a_plain_ymd_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Leniency would be silent: '2026-7-3' compares below every stored 'Y-m-d' bound, so an
        // unnormalised string resolves to an empty clinic and looks like a quiet Tuesday.
        ClinicRoster::forDate($this->clinic(), '2026-7-3');
    }

    /**
     * `RotaGrid`'s bound is 20 for a 780-cell grid; this resolves ONE clinic, and the map (Task 5)
     * may call it once per cell — so the number that matters is that it does not grow with the
     * roster. Measured on a POPULATED unit: an empty fixture only ever proves the empty case
     * (P1d-1 pre-merge finding 3).
     *
     * Named traps this pins, each a defect this codebase has already paid for once: a
     * `Person::levelAt()` per row (40 queries), a `$assignment->unit` per span (60), a
     * `PersonPresenter` call without `withExists(['user as has_account'])` (40 EXISTS), and a
     * narrowed `select()`/`pluck()` on the person query that drops `person_id` — which makes
     * `full_name` and `position` resolve to NULL with no error at all (the P0c defect that broke
     * four live sites with no test coverage), and which the name assertion below is what catches.
     */
    public function test_the_query_count_is_bounded(): void
    {
        $junior = Level::factory()->create(['code' => 'XCQ1', 'display_order' => 10]);
        $senior = Level::factory()->create(['code' => 'XCQ2', 'display_order' => 20]);

        for ($i = 0; $i < 40; $i++) {
            $person = Person::factory()->create(['full_name' => sprintf('Bulk %02d Roster', $i)]);

            if ($i % 4 === 0) {
                // A split cell: two spans on the ward across one period, so a per-span N+1 has
                // something to feed on.
                RotaAssignment::split($person, $this->period, [
                    ['unit_id' => $this->ward->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
                    ['unit_id' => $this->ward->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
                ]);
            } else {
                RotaAssignment::set($person, $this->period, $this->ward);
            }

            LevelAssignment::assign($person, $junior, '2026-07-01');

            // Half the roster is promoted mid-period, so half carry a MULTI-span level history
            // and every row's level must be resolved from it in memory.
            if ($i % 2 === 0) {
                LevelAssignment::assign($person, $senior, '2026-07-10');
            }

            VacationBooking::book($person, '2026-07-13', '2026-07-16', Vacation::GRANULARITY_DATE);

            // Ten stale rows, not one: a union written as one query per stale person costs
            // exactly one query when there is exactly one, so a single fixture could never tell
            // the two implementations apart.
            if ($i < 10) {
                $person->forceFill(['active' => false])->save();
            }
        }

        $rotators = $this->clinic();

        $levels = $this->clinic(['name' => 'Levels clinic']);
        ClinicWriter::setAttendees($levels, Clinic::MODE_LEVELS, [['level_id' => $senior->getKey()]]);

        $named = $this->clinic(['name' => 'Named clinic']);
        ClinicWriter::setAttendees($named, Clinic::MODE_NAMED, array_map(
            fn (int $id): array => ['person_id' => $id],
            Person::query()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $rotatorRoster = ClinicRoster::forDate($rotators, '2026-07-21');
        $rotatorQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $levelRoster = ClinicRoster::forDate($levels, '2026-07-21');
        $levelQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $namedRoster = ClinicRoster::forDate($named, '2026-07-21');
        $namedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(40, $rotatorRoster, 'the rotators clinic did not resolve the whole unit');
        $this->assertCount(20, $levelRoster, 'the levels clinic did not filter on the level held that day');
        $this->assertCount(40, $namedRoster, 'the named clinic did not resolve its whole set');

        // A narrowed person query that dropped the linking column would return rows whose
        // `full_name` is silently null, and every count above would still pass.
        $this->assertNotNull($rotatorRoster[0]['full_name']);

        // MEASURED, not arithmetic. On this exact fixture: rotators 2 (spans, then people),
        // levels 5 (rule rows, spans, people, the level-span query and its eager level load),
        // named 2 (rule rows, then people). The bounds carry one query of headroom each — enough
        // that a legitimate extra read is not a false alarm, tight enough that every N+1 named in
        // the docblock lands well outside: a per-row `Person::levelAt()` is +40 on its own, a
        // per-span unit read +50, a `PersonPresenter` without `withExists()` +40. Verified rather
        // than argued: the set-wise resolver was replaced with a per-row `$person->levelAt($on)`
        // and this test went red at 83 queries against the bound of 7, then restored.
        $this->assertLessThan(4, $rotatorQueries, "rotators mode ran {$rotatorQueries} queries for 40 people");
        $this->assertLessThan(7, $levelQueries, "levels mode ran {$levelQueries} queries for 40 people");
        $this->assertLessThan(4, $namedQueries, "named mode ran {$namedQueries} queries for 40 people");
    }
}
