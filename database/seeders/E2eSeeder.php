<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\Clinics\ClinicWriter;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Illuminate\Database\Seeder;

/**
 * The browser e2e harness's identity. LOCAL ONLY — the Playwright fixtures refuse to run
 * against any non-loopback host, and this seeder is only ever invoked by the harness's
 * global setup against the throwaway e2e sqlite database.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        // HARD STOP. These are fictional accounts with a password published in the repo
        // docs; running this against production would create working logins into a system
        // holding children's health data. There is deliberately no override flag.
        if (app()->environment('production')) {
            throw new \RuntimeException('E2eSeeder must never run in production.');
        }

        $person = Person::updateOrCreate(
            ['email' => 'e2e-admin@example.org'],
            [
                'full_name' => 'E2E Administrator',
                'position' => 0,
                'active' => true,
            ],
        );

        User::updateOrCreate(
            ['member_name' => 'admin'],
            [
                'person_id' => $person->id,
                'password' => 'AdminPass123!',
                'active' => true,
                'pass_exp_date' => now()->format('Y-m-d'),
                // Past first-login setup. RequireSetup redirects every route to /setup while
                // this is null, so without it the browser suite would sign in and then find
                // the onboarding page instead of whatever each spec navigated to.
                'setup_completed_at' => now(),
            ],
        );

        // Master Rota (P1d-1 Task 11): a roster person and one academic year of two periods, so
        // `/admin/rota?year=2026-2027` renders a real grid instead of Task 1's teaching empty
        // state — the plan's own instruction for this task is "extend the seeder rather than
        // weaken the spec". Two blocks, not one, so the split/vacation journey has a period
        // boundary to straddle: 2026-08-14/2026-08-15 is the SAME boundary
        // `VacationEndpointTest::test_a_person_on_leave_shows_the_leave_on_every_period_cell_it_
        // intersects` already exercises, and 2026-08-12 is the same Wednesday
        // `CalendarTest`/`GoldenFixtureTest` already prove resolves to the department week
        // 2026-08-09..2026-08-15 under the [5,6] Friday/Saturday weekend default — reusing it
        // here pins the e2e spec to a fact already proven elsewhere, not a new one.
        Person::updateOrCreate(
            ['email' => 'e2e-rota-resident@example.org'],
            [
                'full_name' => 'E2E Rota Resident',
                'position' => 4,
                'active' => true,
            ],
        );

        if (! Period::query()->where('academic_year', '2026-2027')->exists()) {
            Period::create([
                'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1,
                'label' => 'Block 1', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-14',
            ]);
            Period::create([
                'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 2,
                'label' => 'Block 2', 'starts_on' => '2026-08-15', 'ends_on' => '2026-08-28',
            ]);
        }

        $this->seedReadableRota();
        $this->seedReadableClinic();
    }

    /**
     * CL-05's world (P1e Task 7): ONE clinic on the department's clinic-owning unit, so `/clinics`
     * has something in a known cell for a `clinics.view` reader to find.
     *
     * WHY THE SPEC DOES NOT JUST USE THE ONE IT CREATES. `clinics.spec.js` has two journeys — an
     * administrator DEFINING a clinic, and a resident READING the week — and the second must not
     * depend on the first having run. Playwright's `--grep` runs one test; a reader journey that
     * only passes when the administrator journey ran before it is a spec that is green for a reason
     * nobody chose. So the reader asserts THIS clinic and the administrator creates a different one,
     * on a different day and session (the spec's own comments name both).
     *
     * WHY IT GOES THROUGH `ClinicWriter`. `ClinicWritersAreSingularTest` scans `database/` as well
     * as `app/` (ruling 42): a seeder writing the column directly IS a second writer, merely one
     * whose blast radius stops at the suite. It is also the honest fixture — one built by a
     * different route than the app uses can be valid while the app's own path is broken.
     *
     * THE WEEKDAY IS ISO-8601, Monday = 1 … Sunday = 7 — the same numbering `Calendar` uses and the
     * only one `clinics.weekday` ever holds. Tuesday (2) is chosen because it is a weekday under
     * BOTH plausible weekend settings, so the fixture does not sit in a column a department's own
     * week might render as a weekend.
     *
     * ROTATORS MODE, deliberately: CL-02's default needs no attendee rows, so this fixture adds no
     * person and cannot disturb the two rota specs, which address their people by name. FICTIONAL,
     * AND STAYS THAT WAY (CLAUDE.md).
     */
    private function seedReadableClinic(): void
    {
        $ward = Unit::findByCode('WARD');

        // `ReferenceSeeder` ships WARD as an ACTIVE clinic owner (owner Decision B, P1b), and
        // `prepare-world.js` runs `migrate:fresh --force --seed`, so the cold-start path is what the
        // e2e world takes. Asserted rather than assumed: on an upgrade path `clinic_owner` could be
        // false, and `ClinicWriter::create()` refuses a clinic on a unit that does not own them —
        // which would surface as a seeding exception rather than a silently empty map.
        if ($ward === null || ! $ward->clinic_owner || ! $ward->active) {
            throw new \RuntimeException('E2eSeeder needs an active clinic-owning WARD to seed the clinic map.');
        }

        if (Clinic::query()->where('name', 'E2E Asthma Clinic')->exists()) {
            return;
        }

        ClinicWriter::create($ward, [
            'name' => 'E2E Asthma Clinic',
            'weekday' => 2,
            'session' => 'AM',
            'location' => 'E2E Clinic Room 3',
        ]);
    }

    /**
     * The MR-05 read view's world (P1d-2 Task 6): an actor who holds `rota.view` and NOT
     * `rota.manage`, and a rota with enough shape in it that the availability summary can be
     * asserted against something other than zero.
     *
     * WHY A SECOND ACCOUNT AT ALL. Every other spec in the browser suite signs in as `admin`, who
     * holds every capability in the catalogue — so an administrator reaching `/rota` would say
     * nothing about whether a resident can. Position 4 receives `rota.view` from
     * `AccessControlSeeder::ROLE_DEFAULTS` and never `rota.manage` (owner decision 2, 2026-08-10),
     * which is exactly the actor MR-05 names.
     *
     * WHY THESE PEOPLE ARE NOT "E2E Rota Resident". That person belongs to `master-rota.spec.js`,
     * which addresses their row by name (`hasText: 'E2E Rota Resident'`) and writes through the
     * editor's own controls; giving them a seeded assignment would change what that spec finds in
     * the cell it is about to edit. The two names below are chosen so neither contains that string
     * as a substring, and the read spec never touches the editor's person.
     *
     * THE SHAPE, AND WHY EACH PART OF IT IS THERE:
     *  - a DELIBERATE GAP — PICU for 7 days of a 14-day block — because a summary that can only
     *    count assignments cannot tell a half-planned period from a finished one, and Stage 1's
     *    acceptance (Munawib §35) is that the summaries match reality. The read spec asserts the
     *    same 7 in two renderings: the reader's own cell, and MR-07's per-level/per-unit table.
     *  - TWO LEVELS (R1 and R2) — the level filter needs something to filter to, and a filter that
     *    is offered one option can be "proved" by a screen that ignores it.
     *  - A WEEK'S LEAVE, in Block 2's second week (2026-08-16..22 under the [5,6] weekend default,
     *    which `Calendar`'s own tests already pin). MR-07's "who is on vacation each week" is
     *    otherwise a strip of zeroes. That week is deliberately NOT the one `master-rota.spec.js`
     *    books leave in (2026-08-09..15), so the count the read spec asserts is the same whether
     *    the suite runs whole or one file at a time.
     *
     * FICTIONAL, AND STAYS THAT WAY (CLAUDE.md): no real staff list belongs in this repository, in
     * a fixture or anywhere else.
     *
     * Every write goes through the sanctioned writer — `RotaAssignment`, `VacationBooking`,
     * `LevelAssignment` — never a model create, both because the build guards say so and because a
     * fixture built by a different route than the app uses is a fixture that can be valid while the
     * app's own path is broken.
     */
    private function seedReadableRota(): void
    {
        $blocks = Period::query()->where('academic_year', '2026-2027')->orderBy('position')->get();
        $r1 = Level::query()->where('code', 'R1')->first();
        $r2 = Level::query()->where('code', 'R2')->first();
        $picu = Unit::findByCode('PICU');
        $nicu = Unit::findByCode('NICU');

        if ($blocks->count() < 2 || $r1 === null || $r2 === null || $picu === null || $nicu === null) {
            return;
        }

        [$block1, $block2] = [$blocks[0], $blocks[1]];

        $reader = Person::updateOrCreate(
            ['email' => 'e2e-rota-reader@example.org'],
            ['full_name' => 'E2E Rota Reader', 'short_name' => 'ereader', 'position' => 4, 'active' => true],
        );

        $colleague = Person::updateOrCreate(
            ['email' => 'e2e-rota-colleague@example.org'],
            ['full_name' => 'E2E Rota Colleague', 'short_name' => 'ecolleague', 'position' => 4, 'active' => true],
        );

        // The account. Only the reader gets one: the colleague is roster-only, which is a valid
        // state by construction (P0c — a person with no `users` row cannot authenticate) and keeps
        // the fixture honest about the fact that the rota names people, not accounts.
        User::updateOrCreate(
            ['member_name' => 'resident'],
            [
                'person_id' => $reader->id,
                'password' => 'ResidentPass123!',
                'active' => true,
                'pass_exp_date' => now()->format('Y-m-d'),
                // As for `admin` above: without this, RequireSetup sends every route to /setup.
                'setup_completed_at' => now(),
            ],
        );

        // Effective from before the academic year starts, because `RotaGrid` groups a row by the
        // level held at the YEAR's start — a span opening on the first day of Block 1 would still
        // group correctly, but only by luck of an inclusive bound.
        LevelAssignment::assign($reader, $r1, '2026-07-01');
        LevelAssignment::assign($colleague, $r2, '2026-07-01');

        // `set()` and `split()` both REPLACE this person's spans in the period, so re-running the
        // seeder converges rather than duplicating.
        RotaAssignment::split($reader, $block1, [
            ['unit_id' => $picu->id, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-07'],
        ]);
        RotaAssignment::set($reader, $block2, $picu);
        RotaAssignment::set($colleague, $block1, $nicu);
        RotaAssignment::set($colleague, $block2, $nicu);

        // `book()` has no upsert — a vacation is a row, not a cell — so this one is guarded.
        $onLeave = Vacation::query()->where('person_id', $colleague->id)->exists();

        if (! $onLeave) {
            VacationBooking::book($colleague, '2026-08-16', '2026-08-16', Vacation::GRANULARITY_WEEK);
        }

        // A guard rather than a comment: this fixture only means anything if the rota really is
        // there to be read, and a silently empty grid would make the read spec's failure look like
        // a screen defect rather than a seeding one.
        if (MasterRotaAssignment::query()->count() < 4) {
            throw new \RuntimeException('E2eSeeder failed to seed the readable rota.');
        }
    }
}
