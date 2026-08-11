<?php

namespace Tests\Feature\Clinics;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\ClinicAttendee;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\Clinics\ClinicWriter;
use App\Support\PersonStatus;
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
 * Admin → Structure → Clinics (P1e Task 4). CL-01 and CL-02's ADMINISTRATIVE surface, beside
 * Units, Levels, Calendar, Periods and Holidays because a clinic is department structure
 * (Decision C: management stays on the existing `structure.manage`; only the department-wide MAP
 * gets a new key).
 *
 * THE SCREEN OWNS NO RULES. Every write goes through `App\Support\Clinics\ClinicWriter` and every
 * resolution through `App\Support\Clinics\ClinicRoster`, both of which have their own suites
 * (`ClinicWriterTest`, `ClinicRosterTest`). What is proved HERE is the four things a controller
 * can get wrong on its own: who reaches it, what it OFFERS versus what it ACCEPTS (D9), what
 * reaches the audit trail, and what reaches the Inertia payload.
 *
 * THERE IS NO DESTROY. UN-04's "hides forward, never deletes history" — asserted over the ROUTER
 * rather than by the absence of a method, because a method's absence is invisible to a future PR
 * adding a resource route, and a clinic a P2 condition already references must not be able to
 * vanish.
 */
class ClinicScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
            'code' => 'CSW', 'name' => 'Screen Ward', 'active' => true, 'clinic_owner' => true,
        ]);

        $this->admin = User::factory()->create(['position' => 0]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $this->ward->getKey(),
            'name' => 'General Paediatrics',
            'weekday' => 2,
            'session' => 'AM',
            'location' => 'Clinic B',
            'note' => null,
        ], $overrides);
    }

    private function clinic(array $overrides = []): Clinic
    {
        return ClinicWriter::create($this->ward, array_merge([
            'name' => 'General Paediatrics',
            'weekday' => 2,
            'session' => 'AM',
        ], $overrides));
    }

    // --- Reach ---------------------------------------------------------------------------

    public function test_a_structure_manager_reaches_the_screen_and_a_resident_does_not(): void
    {
        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Clinics'));

        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/clinics')->assertForbidden();
        $this->actingAs($resident)->post('/admin/structure/clinics', $this->payload())->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/clinics')->assertRedirect('/login');
    }

    // --- D9: the offer and the write side are one definition ------------------------------

    /**
     * A picker's write-side validation must match what it OFFERS, per field. Asserted as a MATRIX
     * over four fixtures rather than one happy case — `PickerParityTest`'s shape, and for its
     * reason: the defect this catches is a rule that is merely `exists:units,id`, which is green
     * against the one unit the happy case uses and wrong about the other three.
     */
    public function test_the_unit_picker_offers_only_active_clinic_owning_units(): void
    {
        $nonOwner = Unit::create(['code' => 'CSN', 'name' => 'No Clinics', 'active' => true, 'clinic_owner' => false]);
        $retiredOwner = Unit::create(['code' => 'CSR', 'name' => 'Retired Owner', 'active' => false, 'clinic_owner' => true]);
        $retiredNonOwner = Unit::create(['code' => 'CSX', 'name' => 'Retired Other', 'active' => false, 'clinic_owner' => false]);

        $expected = [
            $this->ward->getKey() => true,
            $nonOwner->getKey() => false,
            $retiredOwner->getKey() => false,
            $retiredNonOwner->getKey() => false,
        ];

        $offered = [];

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(function (Assert $page) use (&$offered): void {
                foreach ($page->toArray()['props']['units'] as $unit) {
                    $offered[] = (int) $unit['id'];
                }
            });

        foreach ($expected as $unitId => $shouldBeOffered) {
            $this->assertSame($shouldBeOffered, in_array($unitId, $offered, true),
                "unit {$unitId} offered={$shouldBeOffered} expected");

            $response = $this->actingAs($this->admin)
                ->post('/admin/structure/clinics', $this->payload(['unit_id' => $unitId, 'name' => 'Parity '.$unitId]));

            if ($shouldBeOffered) {
                $response->assertSessionHasNoErrors();
            } else {
                $response->assertSessionHasErrors('unit_id');
            }
        }
    }

    /**
     * The other half of parity, and the one a `Rule::exists` gets wrong on its own: `Rule::exists`
     * runs on the RAW query builder and never sees SoftDeletes' global scope, so a retired person
     * passes an `exists:people,id` rule while being absent from the offered list.
     */
    public function test_the_named_attendee_picker_offers_and_accepts_the_same_people(): void
    {
        $live = Person::factory()->create(['full_name' => 'Aisha Screen']);
        $inactive = Person::factory()->create(['full_name' => 'Bilal Screen', 'active' => false]);
        $retired = Person::factory()->create(['full_name' => 'Camil Screen']);
        $retired->delete();

        $offered = [];

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(function (Assert $page) use (&$offered): void {
                foreach ($page->toArray()['props']['people'] as $person) {
                    $offered[] = (int) $person['id'];
                }
            });

        $clinic = $this->clinic();

        foreach ([$live->getKey() => true, $inactive->getKey() => false, $retired->getKey() => false] as $id => $ok) {
            $this->assertSame($ok, in_array($id, $offered, true), "person {$id} offer mismatch");

            $response = $this->actingAs($this->admin)->put(
                '/admin/structure/clinics/'.$clinic->getKey().'/attendees',
                ['mode' => Clinic::MODE_NAMED, 'person_ids' => [$id], 'level_ids' => []],
            );

            $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors();
        }
    }

    /**
     * THE LOCKOUT (P1e-1 adversarial review finding 1). A rule list the CHOSEN MODE does not read
     * must not be able to refuse the request.
     *
     * `ClinicAttendeesRequest` applied `levelRule()` to `level_ids.*` and `personRule()` to
     * `person_ids.*` unconditionally, independent of `mode` — while the screen seeded its form
     * with the clinic's WHOLE stored id list, including ids the pickers no longer render a
     * checkbox for. Retire a training level from Admin → Structure → Levels, or deactivate a
     * colleague from Admin → People — both plain, first-class administrative acts — and every
     * subsequent save of that clinic's rule was refused under `level_ids.N` / `person_ids.N`,
     * keys NO ELEMENT ON THE SCREEN RENDERS. `onSuccess` never fired, the panel stayed open, and
     * nothing was reported: a silent no-op.
     *
     * SWITCHING TO `rotators` DID NOT ESCAPE IT, which is what turned a transient annoyance into a
     * permanent one — the mode that needs no rules at all was refused by the same unconditional
     * rules, so there was no reachable state from which the clinic could be repaired.
     *
     * The payloads below are the ones the pre-fix screen actually built, not hand-crafted minima.
     */
    public function test_a_list_the_chosen_mode_ignores_cannot_refuse_the_save(): void
    {
        $clinic = $this->clinic();
        $level = Level::create(['code' => 'XL1', 'name' => 'Lockout 1', 'display_order' => 930, 'active' => true]);
        $departing = Person::factory()->create(['full_name' => 'Farah Departing']);

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_NAMED, 'person_ids' => [$departing->getKey()], 'level_ids' => [],
        ])->assertSessionHasNoErrors();

        // A first-class administrative act, through the one writer of `people.active`. Nothing
        // about it touches `clinic_attendees`, and nothing should have to.
        PersonStatus::apply($departing, false);

        // THE ESCAPE. `rotators` takes no rules at all, so the stale `person_ids` the screen is
        // still carrying must not be consulted — this is the only state from which a locked
        // clinic could be repaired, and it was refused too.
        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_ROTATORS,
            'person_ids' => [$departing->getKey()],
            'level_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Clinic::MODE_ROTATORS, $clinic->fresh()->attendee_mode);
        $this->assertSame(0, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count());

        // And the other crossing: a `levels` save carrying the departed colleague in the list
        // `levels` mode never reads.
        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_LEVELS,
            'level_ids' => [$level->getKey()],
            'person_ids' => [$departing->getKey()],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('clinic_attendees', [
            'clinic_id' => $clinic->getKey(), 'level_id' => $level->getKey(), 'person_id' => null,
        ]);
        $this->assertSame(1, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count());
    }

    /**
     * The same shape from the other side: a retired LEVEL must not lock a `named` clinic, and a
     * level retired through `LevelController`'s plain active toggle is the reproduction — not a
     * hand-built row.
     */
    public function test_a_retired_level_does_not_lock_a_named_clinic(): void
    {
        $clinic = $this->clinic();
        $level = Level::create(['code' => 'XL2', 'name' => 'Lockout 2', 'display_order' => 940, 'active' => true]);
        $person = Person::factory()->create(['full_name' => 'Ghada Named']);

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_LEVELS, 'level_ids' => [$level->getKey()], 'person_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->patch('/admin/structure/levels/'.$level->getKey().'/active', ['active' => false])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_NAMED,
            'person_ids' => [$person->getKey()],
            'level_ids' => [$level->getKey()],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('clinic_attendees', [
            'clinic_id' => $clinic->getKey(), 'person_id' => $person->getKey(), 'level_id' => null,
        ]);
    }

    /**
     * D9, UNMOVED. Narrowing the rules to the mode that reads them must not narrow what that mode
     * accepts: a hand-crafted payload naming a non-offered id in the RELEVANT list is still
     * refused, under the key the relevant picker is rendered against.
     *
     * A soft delete is included beside the two `active` toggles because it freezes the clinic
     * identically and is reached through a different door.
     */
    public function test_the_relevant_list_still_refuses_an_id_the_pickers_never_offered(): void
    {
        $clinic = $this->clinic();
        $retiredLevel = Level::create(['code' => 'XL3', 'name' => 'Lockout 3', 'display_order' => 950, 'active' => false]);
        $inactive = Person::factory()->create(['full_name' => 'Hana Inactive', 'active' => false]);
        $softDeleted = Person::factory()->create(['full_name' => 'Iman Deleted']);
        $softDeleted->delete();

        foreach ([$inactive, $softDeleted] as $person) {
            $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
                'mode' => Clinic::MODE_NAMED, 'person_ids' => [$person->getKey()], 'level_ids' => [],
            ])->assertSessionHasErrors('person_ids.0');
        }

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_LEVELS, 'level_ids' => [$retiredLevel->getKey()], 'person_ids' => [],
        ])->assertSessionHasErrors('level_ids.0');

        // Nothing was written by any of the three.
        $this->assertSame(0, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count());
        $this->assertSame(Clinic::MODE_ROTATORS, $clinic->fresh()->attendee_mode);
    }

    /**
     * `rules()` now READS `mode` before validation runs, to decide which list to ask the offer rule
     * of — so it is a new pre-validation sink for a value the client chooses the SHAPE of. Every
     * value in `$_POST` is a string OR AN ARRAY, and the codebase has already paid for that five
     * times (`ArrayShapedQueryTest`): a `(string)` cast raises `Array to string conversion`, which
     * `HandleExceptions` renders as a 500, and `$request->string()` throws on array input too.
     *
     * The read is a bare `input()` compared with `===`, which answers `false` for an array without
     * raising, leaving the `string`/`Rule::in` pair to refuse it the negotiated way. Asserted rather
     * than assumed, because the difference between the right sink and the wrong one is invisible
     * until somebody posts an array.
     */
    public function test_an_array_shaped_mode_is_a_negotiated_refusal_not_a_500(): void
    {
        $clinic = $this->clinic();

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => [Clinic::MODE_NAMED], 'person_ids' => [], 'level_ids' => [],
        ])->assertSessionHasErrors('mode');

        $this->assertSame(Clinic::MODE_ROTATORS, $clinic->fresh()->attendee_mode);
    }

    // --- The audit trail ------------------------------------------------------------------

    public function test_creating_a_clinic_audits_by_id_and_never_by_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/clinics', $this->payload(['name' => 'Renal Clinic', 'location' => 'Room 9']))
            ->assertSessionHasNoErrors();

        $clinic = Clinic::query()->firstOrFail();
        $row = AuditLog::query()->where('action', 'clinic_create')->latest('id')->firstOrFail();

        $this->assertStringContainsString('clinic='.$clinic->getKey(), (string) $row->detail);
        $this->assertStringContainsString('unit='.$this->ward->getKey(), (string) $row->detail);

        // No PHI and no personal data in the trail — and a clinic's own name and its unit's name
        // are exactly the free text a "details would be helpful here" habit puts in it first.
        $this->assertStringNotContainsString('Renal Clinic', (string) $row->detail);
        $this->assertStringNotContainsString('Room 9', (string) $row->detail);
        $this->assertStringNotContainsString('Screen Ward', (string) $row->detail);
        $this->assertStringNotContainsString('CSW', (string) $row->detail);
    }

    public function test_an_update_audits_the_changed_field_names_and_not_their_values(): void
    {
        $clinic = $this->clinic();

        $this->actingAs($this->admin)->patch('/admin/structure/clinics/'.$clinic->getKey(), $this->payload([
            'name' => 'Renamed Clinic', 'weekday' => 4, 'session' => 'PM', 'location' => 'Room 9',
        ]))->assertSessionHasNoErrors();

        $row = AuditLog::query()->where('action', 'clinic_update')->latest('id')->firstOrFail();

        $this->assertStringContainsString('fields=', (string) $row->detail);
        $this->assertStringContainsString('name', (string) $row->detail);
        $this->assertStringNotContainsString('Renamed Clinic', (string) $row->detail);
        $this->assertStringNotContainsString('Room 9', (string) $row->detail);
    }

    // --- No destroy, proved over the router -------------------------------------------------

    /**
     * UN-04 applied to a clinic: deactivation hides FORWARD. Asserted over the ROUTER, not by the
     * absence of a `destroy()` method — a resource route added in a later PR would put the verb
     * back with no method written by hand, and nothing in a method-absence check would notice.
     */
    public function test_there_is_no_destroy_route_for_a_clinic(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'clinic')) {
                continue;
            }

            if (in_array('DELETE', $route->methods(), true)) {
                $offenders[] = $route->uri().' allows '.implode(',', $route->methods());
            }
        }

        $this->assertSame([], $offenders,
            "A clinic is deactivated, never deleted (UN-04). Found:\n".implode("\n", $offenders));
    }

    /**
     * The vacuity twin the router assertion above cannot do without: a loop over a set that turns
     * out to be empty is green for the wrong reason. If the clinic routes are renamed off the word
     * "clinic", this fails and the DELETE sweep does not.
     */
    public function test_the_clinic_routes_are_actually_registered(): void
    {
        $matched = array_filter(
            iterator_to_array(Route::getRoutes()),
            fn ($route): bool => str_contains($route->uri(), 'clinic')
                && in_array('cap:structure.manage', $route->gatherMiddleware(), true),
        );

        $this->assertNotEmpty($matched, 'no clinic route sits behind cap:structure.manage');
    }

    public function test_deleting_a_clinic_is_a_plain_method_not_allowed(): void
    {
        $clinic = $this->clinic();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/clinics/'.$clinic->getKey())
            ->assertStatus(405);

        $this->assertDatabaseHas('clinics', ['id' => $clinic->getKey()]);
    }

    public function test_a_clinic_that_stopped_running_is_deactivated_and_can_be_revived(): void
    {
        $clinic = $this->clinic();

        $this->actingAs($this->admin)
            ->patch('/admin/structure/clinics/'.$clinic->getKey().'/active', ['active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $clinic->fresh()->active);
        $this->assertDatabaseHas('audit_log', ['action' => 'clinic_deactivate']);

        $this->actingAs($this->admin)
            ->patch('/admin/structure/clinics/'.$clinic->getKey().'/active', ['active' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $clinic->fresh()->active);
        $this->assertDatabaseHas('audit_log', ['action' => 'clinic_activate']);
    }

    /**
     * A REFUSED RESTART IS REPORTED, UNDER THE KEY THE SCREEN READS (P1e-1 adversarial review
     * finding 3).
     *
     * `ClinicWriter::setActive()` refuses to revive a clinic onto a unit that has since been
     * retired or stopped owning clinics — a real state, since a unit may be retired UNDER a clinic
     * that already exists. The refusal was flashed under `active` and nothing on the clinics screen
     * read it, so the Restart button appeared to do nothing at all.
     *
     * The key half is asserted here and the RENDER SITE in `tests/js/Clinics.test.js`
     * ("renders a refused stop or restart against the clinic it was pressed on"), which is ruling
     * 41's two-halves discipline: without the second, a template that stopped reading
     * `errors.active` would take this refusal silent again and leave this assertion green.
     */
    public function test_a_refused_restart_is_flashed_under_the_key_the_screen_renders(): void
    {
        $clinic = $this->clinic();

        $this->actingAs($this->admin)
            ->patch('/admin/structure/clinics/'.$clinic->getKey().'/active', ['active' => false])
            ->assertSessionHasNoErrors();

        $this->ward->update(['active' => false]);

        $this->actingAs($this->admin)
            ->patch('/admin/structure/clinics/'.$clinic->getKey().'/active', ['active' => true])
            ->assertSessionHasErrors('active');

        $this->assertFalse((bool) $clinic->fresh()->active);
        $this->assertDatabaseMissing('audit_log', ['action' => 'clinic_activate']);

        // The other half of the same refusal: a unit that is still live but no longer owns clinics.
        $this->ward->update(['active' => true, 'clinic_owner' => false]);

        $this->actingAs($this->admin)
            ->patch('/admin/structure/clinics/'.$clinic->getKey().'/active', ['active' => true])
            ->assertSessionHasErrors('active');

        $this->assertFalse((bool) $clinic->fresh()->active);
    }

    // --- The payload -----------------------------------------------------------------------

    /**
     * THE ONE THAT FAILS ON THE OBVIOUS IMPLEMENTATION. The administrator editing this screen
     * holds `people.manage`, which is one of the two branches of `PersonPolicy::viewContact()`;
     * the department is also switched to the most permissive visibility setting, which is the
     * other. Passing the request's user into the presenter — the natural thing to write — would
     * therefore emit every colleague's contact detail into the props of a screen that renders
     * none of it, exactly as P1d-2 found on the rota grid.
     *
     * The whole KEY SET is asserted, not a handful of named absences: a named-absence assertion
     * cannot catch a field nobody thought to list.
     */
    public function test_the_attendee_picker_is_contact_free(): void
    {
        Institution::current()->update(['contact_visibility' => 'members']);
        Calendar::flush();

        Person::factory()->create(['full_name' => 'Dania Screen', 'email' => 'dania@example.test', 'phone' => '0500000000']);

        $admin = User::factory()->create(['position' => 0]);
        $this->assertTrue($admin->can('people.manage'), 'the acting administrator must hold people.manage');

        $this->actingAs($admin)->get('/admin/structure/clinics')
            ->assertInertia(function (Assert $page): void {
                $people = $page->toArray()['props']['people'];

                $this->assertNotSame([], $people, 'the named-attendee picker offered nobody');

                foreach ($people as $person) {
                    $this->assertSame(
                        ['id', 'full_name', 'short_name', 'position', 'external', 'active', 'retired', 'has_account', 'joined_at'],
                        array_keys($person),
                    );
                }
            });
    }

    public function test_switching_to_rotators_mode_clears_the_attendee_rows_through_the_writer(): void
    {
        $clinic = $this->clinic();
        $person = Person::factory()->create();

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_NAMED, 'person_ids' => [$person->getKey()], 'level_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count());
        $this->assertSame(Clinic::MODE_NAMED, $clinic->fresh()->attendee_mode);

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_ROTATORS, 'person_ids' => [], 'level_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, ClinicAttendee::query()->where('clinic_id', $clinic->getKey())->count());
        $this->assertSame(Clinic::MODE_ROTATORS, $clinic->fresh()->attendee_mode);
        $this->assertDatabaseHas('audit_log', ['action' => 'clinic_attendees_set']);
    }

    /**
     * A writer refusal is a VALIDATION message, not a 500. `ClinicWriter` throws on every
     * inexpressible-in-SQL rule (a `levels` clinic with no levels attached is one), and those
     * throws happen BEFORE its transaction opens — the controller's job is to turn them into
     * something the administrator can read.
     */
    public function test_a_writer_refusal_reaches_the_screen_as_an_error_not_a_500(): void
    {
        $clinic = $this->clinic();

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_LEVELS, 'person_ids' => [], 'level_ids' => [],
        ])->assertSessionHasErrors();

        $this->assertSame(Clinic::MODE_ROTATORS, $clinic->fresh()->attendee_mode);
    }

    public function test_the_levels_picker_offers_active_levels_and_the_mode_stores_them(): void
    {
        $level = Level::create(['code' => 'XS1', 'name' => 'Screen 1', 'display_order' => 910, 'active' => true]);
        $retired = Level::create(['code' => 'XS2', 'name' => 'Screen 2', 'display_order' => 920, 'active' => false]);

        $offered = [];

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(function (Assert $page) use (&$offered): void {
                foreach ($page->toArray()['props']['levels'] as $row) {
                    $offered[] = (int) $row['id'];
                }
            });

        $this->assertContains($level->getKey(), $offered);
        $this->assertNotContains($retired->getKey(), $offered);

        $clinic = $this->clinic();

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_LEVELS, 'level_ids' => [$level->getKey()], 'person_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('clinic_attendees', [
            'clinic_id' => $clinic->getKey(), 'level_id' => $level->getKey(), 'person_id' => null,
        ]);

        $this->actingAs($this->admin)->put('/admin/structure/clinics/'.$clinic->getKey().'/attendees', [
            'mode' => Clinic::MODE_LEVELS, 'level_ids' => [$retired->getKey()], 'person_ids' => [],
        ])->assertSessionHasErrors();
    }

    // --- The department's own week ----------------------------------------------------------

    /**
     * Decision A: the stored integer is absolute (ISO-8601, Monday = 1 … Sunday = 7) and only
     * PRESENTATION rotates. The screen receives the ordered, labelled week from `Calendar` and
     * computes nothing — so changing the department's weekend re-orders the picker with no stored
     * value changing and no migration.
     */
    public function test_the_weekday_options_come_from_the_calendar_and_are_in_the_departments_order(): void
    {
        // The QCH default: Friday and Saturday off, so the week begins on Sunday.
        Institution::current()->update(['weekend_days' => [5, 6]]);
        Calendar::flush();

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekdays', Calendar::weekdayColumns())
                ->where('weekdays.0.iso', 7)
                ->where('weekdays.0.label', 'Sunday')
                ->where('weekdays.5.weekend', true)
            );

        // A Saturday–Sunday weekend department gets a Monday-first week from the same column, with
        // nothing in `clinics` rewritten.
        $clinic = $this->clinic(['weekday' => 2]);

        Institution::current()->update(['weekend_days' => [6, 7]]);
        Calendar::flush();

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekdays.0.iso', 1)
                ->where('weekdays.0.label', 'Monday')
            );

        $this->assertSame(2, (int) $clinic->fresh()->weekday);
    }

    public function test_every_listed_clinic_carries_a_server_built_weekday_label(): void
    {
        $this->clinic(['weekday' => 3, 'session' => 'PM']);

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clinics.0.unit.code', 'CSW')
                ->where('clinics.0.clinics.0.weekday', 3)
                ->where('clinics.0.clinics.0.weekday_label', 'Wednesday')
                ->where('clinics.0.clinics.0.session_label', 'Afternoon')
                ->where('clinics.0.clinics.0.mode_label', Clinic::ATTENDEE_MODES[Clinic::MODE_ROTATORS])
            );
    }

    /**
     * A unit may be retired UNDER a clinic that already exists — `ClinicWriter` refuses to CREATE
     * or REVIVE one on a retired unit, which is a different question from whether an existing
     * clinic stays visible. An administrator who cannot SEE it cannot retire it either (UN-04's
     * own reasoning for listing retired units on the units screen).
     */
    public function test_a_clinic_on_a_since_retired_unit_is_still_listed(): void
    {
        $this->clinic();
        $this->ward->update(['active' => false]);

        $offered = [];

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(function (Assert $page) use (&$offered): void {
                $page->has('clinics', 1)
                    ->where('clinics.0.unit.code', 'CSW')
                    ->where('clinics.0.unit.active', false);

                foreach ($page->toArray()['props']['units'] as $unit) {
                    $offered[] = (string) $unit['code'];
                }
            });

        // Listed, but no longer offered as somewhere to put a NEW clinic. ReferenceSeeder's own
        // WARD is a live clinic owner, so this asserts the retired unit's absence rather than an
        // empty list — an empty-list assertion here would be asserting the seeder, not the rule.
        $this->assertNotContains('CSW', $offered);
    }

    /**
     * The listing costs a fixed handful plus a bounded amount PER CLINIC, and nothing per person on
     * the roster. Pinned on a POPULATED department, because a bound measured on an empty one only
     * ever proves the empty case — `ClinicRosterTest::test_the_query_count_is_bounded`'s own reason.
     *
     * The named traps, each already paid for once in this codebase: the two picker offers built
     * again inside the listing instead of once (that was real here, and this is what caught it); a
     * per-person `levelAt()` inside the resolver; a `$clinic->unit` per row.
     *
     * MEASURED AND THEN TIGHTENED, because a bound is only worth what it refuses. Three clinics on a
     * thirty-person unit cost **18**; re-planting the per-clinic offer rebuild took it to **23**, and
     * the first bound written here (25) was green against that plant — a bound with more headroom
     * than the regression it exists to catch is decoration. Twenty leaves two queries of slack and
     * was watched failing against the plant before being trusted.
     */
    public function test_the_listing_cost_does_not_grow_with_the_roster(): void
    {
        $today = Calendar::todayYmd();

        $period = Period::create([
            'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Block 1',
            'starts_on' => CarbonImmutable::parse($today)->subDays(3)->format('Y-m-d'),
            'ends_on' => CarbonImmutable::parse($today)->addDays(3)->format('Y-m-d'),
        ]);

        for ($i = 0; $i < 30; $i++) {
            RotaAssignment::set(Person::factory()->create(['full_name' => 'Roster '.$i]), $period, $this->ward);
        }

        $this->clinic(['name' => 'One', 'weekday' => 1]);
        $this->clinic(['name' => 'Two', 'weekday' => 2]);
        $this->clinic(['name' => 'Three', 'weekday' => 3]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin)->get('/admin/structure/clinics')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $count, "the clinics listing issued {$count} queries");
    }

    // --- "and who is that today?" -------------------------------------------------------------

    /**
     * A refinement rule that cannot be checked against reality is a rule nobody trusts. The detail
     * panel resolves each clinic through `ClinicRoster` as of TODAY, which is what makes
     * `rotators`/`levels`/`named` legible rather than abstract.
     */
    public function test_a_clinic_shows_who_it_resolves_to_today(): void
    {
        $today = Calendar::todayYmd();
        $start = CarbonImmutable::parse($today)->subDays(3)->format('Y-m-d');
        $end = CarbonImmutable::parse($today)->addDays(3)->format('Y-m-d');

        $period = Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start,
            'ends_on' => $end,
        ]);

        $onWard = Person::factory()->create(['full_name' => 'Eman Screen']);
        RotaAssignment::set($onWard, $period, $this->ward);

        $this->clinic();

        $this->actingAs($this->admin)->get('/admin/structure/clinics')
            ->assertInertia(fn (Assert $page) => $page
                ->where('today', Calendar::label($today))
                ->has('clinics.0.clinics.0.resolved_today', 1)
                ->where('clinics.0.clinics.0.resolved_today.0.id', $onWard->getKey())
                ->where('clinics.0.clinics.0.resolved_today.0.full_name', 'Eman Screen')
            );
    }
}
