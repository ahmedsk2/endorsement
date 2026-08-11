<?php

namespace Tests\Feature\Clinics;

use App\Models\Capability;
use App\Models\Clinic;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\Calendar;
use App\Support\Clinics\ClinicWriter;
use App\Support\Rota\RotaAssignment;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CL-05's weekly clinic map at `/clinics` (P1e Task 5, Decision C).
 *
 * A NEW CAPABILITY, `clinics.view`, SEEDED TO EVERY SEEDED POSITION — the `rota.view` shape and
 * for the `rota.view` reason: a resident needs to know when their unit's clinic runs. Managing
 * clinics stays on `structure.manage`. Munawib §5's footnote contemplates the clinic map being
 * exposed WITHOUT a login; D7 overrides it and this file asserts the override
 * ({@see test_a_guest_is_redirected_to_login_and_the_map_is_not_anonymous}).
 *
 * READ-ONLY AS A PROPERTY OF THE ROUTE GROUP, NOT AS A LIST OF 403 CASES. Because the capability
 * reaches everybody, a write endpoint arriving in this group would be writable by the whole
 * department — so the GET-only property is asserted over the ROUTER, which is what makes it hold
 * for routes nobody has written yet. Its VACUITY TWIN ships with it and is not optional: Task 4's
 * DELETE sweep passed on its very first red run because no clinic route existed to iterate, and a
 * sweep that proves nothing when the group is renamed or deleted is not a guard.
 *
 * NO PERSON-SHAPED VALUE REACHES THE PAYLOAD AT ALL, which is deliberately stronger than "no
 * contact field for this viewer". A clinic is a WEEKLY RECURRENCE WITH NO DATE OF ITS OWN, so
 * there is no honest date to resolve a map cell as of — `ClinicRoster::forDate()` answers for a
 * DAY, and resolving Tuesday's clinic as of today (a Thursday) reports Thursday's rota with
 * complete confidence. The map therefore shows the RULE, never the roster: a mode label and, for
 * `levels`, the training-level codes, which are structure rather than people. That leaves nothing
 * on this surface to gate, and {@see test_the_map_carries_no_contact_field_for_any_viewer} asserts
 * it under the single most permissive combination the system can produce — an administrator
 * holding `people.manage` on a department switched to `contact_visibility = members`, both
 * branches of `PersonPolicy::viewContact()` true at once. A resident on the default setting would
 * pass with the disclosure fully present, which is how P1d-2 found the live one on the rota grid.
 */
class ClinicMapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Props every Inertia page carries from `HandleInertiaRequests::share()`. Excluded from the
     * PERSON-SHAPE scan only — `auth.user.full_name` is the signed-in reader's own name in the
     * layout's header, which is not a disclosure about a colleague. The CONTACT scan deliberately
     * does not exclude them, exactly as `RotaReadViewTest` decided: no shared prop carries a
     * contact field today, and if one ever does this test should be what says so.
     */
    private const SHARED_PROPS = ['auth', 'nav', 'shift', 'flash', 'errors'];

    private Unit $ward;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
        Calendar::flush();

        // `Unit` has NO factory (CLAUDE.md) and both `active` and `clinic_owner` default to FALSE,
        // so a unit built without them is retired and owns no clinics on arrival.
        $this->ward = Unit::create([
            'code' => 'CMW', 'name' => 'Map Ward', 'active' => true, 'clinic_owner' => true,
        ]);
    }

    private function clinic(array $overrides = [], ?Unit $unit = null): Clinic
    {
        return ClinicWriter::create($unit ?? $this->ward, array_merge([
            'name' => 'General Paediatrics',
            'weekday' => 2,
            'session' => 'AM',
        ], $overrides));
    }

    // --- The capability ---------------------------------------------------------------------

    public function test_clinics_view_is_in_the_catalog(): void
    {
        $this->assertNotNull(Capability::where('key', 'clinics.view')->first());
    }

    /**
     * Finding 11: `docs/spec/08-foundation.md` is the capability catalog document, and a key that
     * is not added there goes stale silently. Asserted here rather than left to `RotaAccessTest`,
     * whose name mentions no clinic.
     */
    public function test_the_catalog_document_lists_the_key(): void
    {
        $doc = (string) file_get_contents(base_path('docs/spec/08-foundation.md'));

        $this->assertStringContainsString('`clinics.view`', $doc);
    }

    public function test_every_seeded_position_holds_clinics_view_by_default(): void
    {
        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position]);

            $this->assertTrue(
                AccessControl::allows($user, 'clinics.view'),
                "position {$position} should hold clinics.view"
            );
        }
    }

    public function test_the_retired_nurse_position_gains_no_default(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->assertFalse(AccessControl::allows($nurse, 'clinics.view'));
    }

    public function test_managing_clinics_still_needs_structure_manage(): void
    {
        // Decision C: ONE new key, not two. A resident holds `clinics.view` and reaches the map;
        // the administrative screen stays where it was.
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/clinics')->assertOk();
        $this->actingAs($resident)->get('/admin/structure/clinics')->assertForbidden();
    }

    // --- GET-only, over the router ------------------------------------------------------------

    /**
     * `clinics.view` is seeded to every authenticated position, so anything reachable with it is
     * reachable by the whole department. Enumerated over the ROUTER rather than as a list of 403
     * cases: a hand-written list only covers the routes somebody remembered to add to it, and the
     * failure this guards against is a future PR hanging a write endpoint off the read group.
     */
    public function test_every_route_behind_cap_clinics_view_is_a_get(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('cap:clinics.view', $route->gatherMiddleware(), true)) {
                continue;
            }

            if ($route->methods() !== ['GET', 'HEAD']) {
                $offenders[] = $route->uri().' allows '.implode(',', $route->methods());
            }
        }

        $this->assertSame([], $offenders,
            "A write route behind cap:clinics.view would be writable by every member of the department.\n"
            .implode("\n", $offenders));
    }

    /**
     * THE VACUITY TWIN, and it is not optional. Task 4's DELETE sweep passed on its first red run
     * because no clinic route was registered yet and the loop iterated an empty set. If the
     * `cap:clinics.view` group is deleted or renamed, this fails and the GET-only assertion does
     * not.
     */
    public function test_the_map_route_is_actually_registered_behind_cap_clinics_view(): void
    {
        $matched = array_filter(
            iterator_to_array(Route::getRoutes()),
            fn ($route): bool => in_array('cap:clinics.view', $route->gatherMiddleware(), true),
        );

        $this->assertNotEmpty($matched, 'no route sits behind cap:clinics.view — CL-05 has no map');

        $uris = array_map(fn ($route): string => $route->uri(), $matched);

        $this->assertContains('clinics', $uris);
    }

    /** The router property, restated at runtime: the map's URL answers no write verb. */
    public function test_posting_to_the_map_is_a_plain_method_not_allowed(): void
    {
        $reader = User::factory()->create(['position' => 4]);

        $this->actingAs($reader)->post('/clinics', ['name' => 'x'])->assertStatus(405);
        $this->actingAs($reader)->delete('/clinics')->assertStatus(405);
    }

    // --- D7 ------------------------------------------------------------------------------------

    public function test_a_guest_is_redirected_to_login_and_the_map_is_not_anonymous(): void
    {
        $this->get('/clinics')->assertRedirect('/login');

        // Munawib §5's footnote lists the clinic map among the link-public surfaces. D7 overrides
        // it: the route carries `auth`, asserted over the router so the redirect above cannot be
        // some other middleware's doing.
        $map = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'clinics');

        $this->assertNotNull($map);
        $this->assertContains('auth', $map->gatherMiddleware());
    }

    // --- The payload ---------------------------------------------------------------------------

    public function test_a_reader_sees_the_map_with_its_clinics(): void
    {
        $this->clinic(['name' => 'Renal Clinic', 'weekday' => 3, 'session' => 'PM', 'location' => 'Room 9']);

        $reader = User::factory()->create(['position' => 4]);

        $this->actingAs($reader)->get('/clinics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clinics/Map')
                ->has('weekdays', 7)
                ->has('sessions')
                ->has('rows'));

        $props = $this->propsFrom($this->actingAs($reader)->get('/clinics'));

        $row = $this->rowFor($props, 'CMW');

        $this->assertSame([
            ['id' => $this->clinicId($props, 'Renal Clinic'), 'name' => 'Renal Clinic', 'location' => 'Room 9', 'note' => null,
                'who' => Clinic::ATTENDEE_MODES[Clinic::MODE_ROTATORS], 'who_detail' => null],
        ], $row['cells']['3-PM']);
    }

    /**
     * DECISION A, ASKED OF THE PAYLOAD. The stored integer is absolute ISO-8601; only presentation
     * rotates. A department that changes its weekend re-orders every column here with no stored
     * value changing and no migration.
     */
    public function test_the_columns_are_the_departments_own_week_in_its_own_order(): void
    {
        $reader = User::factory()->create(['position' => 4]);

        // The QCH default: Friday and Saturday off, so the week begins on Sunday.
        Institution::current()->update(['weekend_days' => [5, 6]]);
        Calendar::flush();

        $props = $this->propsFrom($this->actingAs($reader)->get('/clinics'));

        $this->assertSame([7, 1, 2, 3, 4, 5, 6], array_column($props['weekdays'], 'iso'));
        $this->assertSame([false, false, false, false, false, true, true], array_column($props['weekdays'], 'weekend'));

        // A department that takes Saturday and Sunday off starts its week on Monday instead.
        Institution::current()->update(['weekend_days' => [6, 7]]);
        Calendar::flush();

        $props = $this->propsFrom($this->actingAs($reader)->get('/clinics'));

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($props['weekdays'], 'iso'));
        $this->assertSame([false, false, false, false, false, true, true], array_column($props['weekdays'], 'weekend'));
    }

    public function test_an_inactive_clinic_and_a_retired_unit_are_absent_from_the_map(): void
    {
        $running = $this->clinic(['name' => 'Still Running', 'weekday' => 1]);
        $stopped = $this->clinic(['name' => 'Stopped Clinic', 'weekday' => 1, 'session' => 'PM']);
        ClinicWriter::setActive($stopped, false);

        // A unit may be retired UNDER a clinic that already exists — `ClinicWriter` refuses to
        // create or revive one on a retired unit, which is a different question.
        $closing = Unit::create(['code' => 'CMC', 'name' => 'Closing Ward', 'active' => true, 'clinic_owner' => true]);
        $this->clinic(['name' => 'Orphan Clinic', 'weekday' => 4], $closing);
        $closing->update(['active' => false]);

        $props = $this->propsFrom($this->actingAs(User::factory()->create(['position' => 4]))->get('/clinics'));

        $names = $this->clinicNames($props);

        $this->assertContains('Still Running', $names, 'the map dropped a clinic that is running');
        $this->assertNotContains('Stopped Clinic', $names);
        $this->assertNotContains('Orphan Clinic', $names);
        $this->assertNotContains('CMC', array_column(array_column($props['rows'], 'unit'), 'code'));

        // Non-vacuity: the row that SHOULD be there is, so the two absences above are not simply
        // an empty map.
        $this->assertNotNull($running->fresh());
        $this->assertContains('CMW', array_column(array_column($props['rows'], 'unit'), 'code'));
    }

    /** Task 2 case 9's consequence: nothing in the schema makes a cell hold one clinic. */
    public function test_two_clinics_in_one_cell_both_appear(): void
    {
        $this->clinic(['name' => 'Asthma Clinic', 'weekday' => 2, 'session' => 'AM']);
        $this->clinic(['name' => 'Diabetes Clinic', 'weekday' => 2, 'session' => 'AM']);

        $props = $this->propsFrom($this->actingAs(User::factory()->create(['position' => 4]))->get('/clinics'));

        $cell = $this->rowFor($props, 'CMW')['cells']['2-AM'];

        $this->assertSame(['Asthma Clinic', 'Diabetes Clinic'], array_column($cell, 'name'));
    }

    /**
     * The refinement rule reaches the map as STRUCTURE, never as people: `levels` mode shows the
     * training-level codes it is attached to, and `named` mode shows its label and nothing else.
     */
    public function test_a_refined_clinic_shows_its_rule_and_not_its_roster(): void
    {
        $levelled = $this->clinic(['name' => 'Levels Clinic', 'weekday' => 5]);
        $named = $this->clinic(['name' => 'Named Clinic', 'weekday' => 5, 'session' => 'PM']);

        $r1 = Level::query()->where('code', 'R1')->firstOrFail();
        $r2 = Level::query()->where('code', 'R2')->firstOrFail();

        ClinicWriter::setAttendees($levelled, Clinic::MODE_LEVELS, [
            ['level_id' => $r1->getKey()], ['level_id' => $r2->getKey()],
        ]);

        $person = Person::factory()->create(['full_name' => 'Named Attendee']);
        ClinicWriter::setAttendees($named, Clinic::MODE_NAMED, [['person_id' => $person->getKey()]]);

        $props = $this->propsFrom($this->actingAs(User::factory()->create(['position' => 4]))->get('/clinics'));

        $cells = $this->rowFor($props, 'CMW')['cells'];

        $this->assertSame(Clinic::ATTENDEE_MODES[Clinic::MODE_LEVELS], $cells['5-AM'][0]['who']);
        $this->assertSame('R1, R2', $cells['5-AM'][0]['who_detail']);

        $this->assertSame(Clinic::ATTENDEE_MODES[Clinic::MODE_NAMED], $cells['5-PM'][0]['who']);
        $this->assertNull($cells['5-PM'][0]['who_detail'], 'a named rule must not put a colleague on the map');
    }

    /**
     * THE ONE THAT FAILS ON THE OBVIOUS IMPLEMENTATION — "show who attends" resolved through the
     * request's user.
     *
     * The department is on the most permissive contact setting AND the viewer holds
     * `people.manage`, so both branches of `PersonPolicy::viewContact()` are true at once. The
     * assertion is over the WHOLE props tree by KEY NAME, not a handful of named absences on a
     * shape somebody chose: a named-absence assertion cannot catch a field nobody thought to list.
     */
    public function test_the_map_carries_no_contact_field_for_any_viewer(): void
    {
        $clinic = $this->clinic(['name' => 'Contact Clinic', 'weekday' => 2]);

        // A person with BOTH contact fields populated, named on the clinic AND on the unit's rota
        // — so an implementation that resolved the cell would have had something real to leak. A
        // fixture with a null phone would make this pass for the wrong reason.
        $person = Person::factory()->create([
            'full_name' => 'Dania Map', 'email' => 'dania.map@example.test', 'phone' => '0500000000',
            'notes' => 'a supervisor note', 'constraints' => 'no nights',
        ]);
        ClinicWriter::setAttendees($clinic, Clinic::MODE_NAMED, [['person_id' => $person->getKey()]]);

        $today = Calendar::todayYmd();
        $period = Period::create([
            'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1, 'label' => 'Block 1',
            'starts_on' => CarbonImmutable::parse($today)->subDays(3)->format('Y-m-d'),
            'ends_on' => CarbonImmutable::parse($today)->addDays(3)->format('Y-m-d'),
        ]);
        RotaAssignment::set($person, $period, $this->ward);

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);
        Calendar::flush();

        $this->assertTrue(
            \App\Support\ContactVisibility::membersMaySeeContact(),
            'the institution fixture is not actually on CONTACT_MEMBERS — this test would prove nothing'
        );

        foreach ([4, 0] as $position) {
            $viewer = User::factory()->create(['position' => $position]);

            if ($position === 0) {
                $this->assertTrue($viewer->can('people.manage'), 'the acting administrator must hold people.manage');
            }

            $props = $this->propsFrom($this->actingAs($viewer)->get('/clinics'));

            // Non-vacuity: the clinic whose rule names that person is on the map.
            $this->assertContains('Contact Clinic', $this->clinicNames($props),
                "position {$position} did not receive the clinic at all");

            $this->assertSame([], $this->keyPaths($props, ['email', 'phone', 'notes', 'constraints']),
                "position {$position} received a contact field in the /clinics props");

            // Stronger than contact-free: NO person-shaped value ships at all, so there is nothing
            // here to gate and no second projection for a reviewer to check.
            $own = array_diff_key($props, array_flip(self::SHARED_PROPS));

            $this->assertSame([], $this->keyPaths($own, ['full_name', 'short_name', 'person_id', 'has_account']),
                "position {$position} received a person-shaped value on the clinic map");

            $this->assertStringNotContainsString('Dania Map', json_encode($own, JSON_THROW_ON_ERROR));
        }
    }

    // --- Cost -----------------------------------------------------------------------------------

    /**
     * The map costs a fixed handful for the whole page and nothing per clinic or per person.
     *
     * Pinned on a POPULATED department, because a bound measured on an empty one only ever proves
     * the empty case. The named traps, each already paid for once here: a `$clinic->unit` per row;
     * a level lookup per clinic instead of once for the page; a `ClinicRoster` resolution per cell.
     *
     * MEASURED AND THEN CHECKED AGAINST A PLANT, because a bound nobody has watched fail is
     * decoration. Three clinic-owning units, twenty-four clinics (six of them level-refined) and
     * thirty people on the unit's rota cost **10**; replacing the one page-wide level lookup with
     * a per-clinic one took it to **16**, and this bound — twelve, two queries of slack against a
     * regression worth six — was watched failing against that plant before being trusted.
     */
    public function test_the_map_issues_a_bounded_number_of_queries(): void
    {
        $units = [$this->ward];

        foreach (['CM1', 'CM2'] as $code) {
            $units[] = Unit::create([
                'code' => $code, 'name' => 'Map Unit '.$code, 'active' => true, 'clinic_owner' => true,
            ]);
        }

        $today = Calendar::todayYmd();
        $period = Period::create([
            'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1, 'label' => 'Block 1',
            'starts_on' => CarbonImmutable::parse($today)->subDays(3)->format('Y-m-d'),
            'ends_on' => CarbonImmutable::parse($today)->addDays(3)->format('Y-m-d'),
        ]);

        for ($i = 0; $i < 30; $i++) {
            RotaAssignment::set(Person::factory()->create(['full_name' => 'Roster '.$i]), $period, $this->ward);
        }

        $r1 = Level::query()->where('code', 'R1')->firstOrFail();

        foreach ($units as $unit) {
            foreach ([1, 2, 3, 4] as $weekday) {
                foreach (['AM', 'PM'] as $session) {
                    $clinic = ClinicWriter::create($unit, [
                        'name' => 'Clinic '.$unit->code.$weekday.$session,
                        'weekday' => $weekday,
                        'session' => $session,
                    ]);

                    if ($weekday === 2) {
                        ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['level_id' => $r1->getKey()]]);
                    }
                }
            }
        }

        $reader = User::factory()->create(['position' => 4]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($reader)->get('/clinics')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $count, "the clinic map issued {$count} queries");
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function rowFor(array $props, string $code): array
    {
        foreach ($props['rows'] as $row) {
            if ($row['unit']['code'] === $code) {
                return $row;
            }
        }

        $this->fail("the map has no row for unit {$code}");
    }

    /** @return list<string> */
    private function clinicNames(array $props): array
    {
        $names = [];

        foreach ($props['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                foreach ($cell as $clinic) {
                    $names[] = $clinic['name'];
                }
            }
        }

        return $names;
    }

    private function clinicId(array $props, string $name): int
    {
        foreach ($props['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                foreach ($cell as $clinic) {
                    if ($clinic['name'] === $name) {
                        return $clinic['id'];
                    }
                }
            }
        }

        $this->fail("the map has no clinic named {$name}");
    }

    /**
     * Every path at which one of `$needles` appears as an ARRAY KEY, at any depth. Key names
     * rather than values: a withheld field must be ABSENT from the props, never null, because the
     * two look identical on screen and a future consumer would eventually render one as the other.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function keyPaths(mixed $node, array $needles, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && in_array($key, $needles, true)) {
                $found[] = $here;
            }

            $found = array_merge($found, $this->keyPaths($value, $needles, $here));
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function propsFrom(\Illuminate\Testing\TestResponse $response): array
    {
        $captured = null;

        $response->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $page->toArray()['props'];
        });

        $this->assertIsArray($captured);

        return $captured;
    }
}
