<?php

namespace Database\Seeders;

use App\Models\Period;
use App\Models\Person;
use App\Models\User;
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
    }
}
