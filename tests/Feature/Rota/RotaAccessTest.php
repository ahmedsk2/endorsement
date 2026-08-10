<?php

namespace Tests\Feature\Rota;

use App\Models\Capability;
use App\Models\RoleCapability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib MR-02/MR-05. Two capabilities, deliberately separate:
 *
 *  - `rota.view` — READ the master rota. Defaults to EVERY seeded position, because MR-05's
 *    whole point is that residents read it. Position 1 (Nurse) is RETIRED and gets no defaults,
 *    ever.
 *  - `rota.manage` — EDIT it. Defaults to **Administrator ONLY** (owner decision 2, 2026-08-10).
 *    An administrator grants it per department from Admin → Access Control, the same shape
 *    `structure.manage` and `people.manage` already ship in.
 *
 *    **This REVERSES what P1d-1 shipped.** The 2026-08-09 decision defaulted it to Administrator
 *    AND Chief Resident (Chief Resident as Munawib's Scheduler persona); the owner reversed that
 *    on 2026-08-10 and P1d-2 Task 1 corrected all four sites. It is recorded here so a later
 *    reader does not "restore" the Chief Resident default from a stale memory of the P1d-1 plan:
 *    the newer decision is the binding one, and Chief Resident holding `rota.manage` is now a
 *    department's explicit grant rather than a default.
 *
 *    `AccessControlSeeder` applies each default ONCE (`applied_role_defaults`) and never
 *    re-asserts, so an instance that already received the P1d-1 grant KEEPS it. That is by
 *    design, not a gap: revoking a capability an administrator may since have deliberately kept
 *    is exactly what that seeder refuses to do. The remedy is an operator un-tick, documented in
 *    `docs/RUNBOOK-DEPLOY.md`. There is no data migration and there must not be one.
 *
 * D7: both routes sit behind `auth`. There is no anonymous route anywhere in this platform and
 * this plan adds none.
 */
class RotaAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_both_capabilities_are_in_the_catalog(): void
    {
        $this->assertNotNull(Capability::where('key', 'rota.view')->first());
        $this->assertNotNull(Capability::where('key', 'rota.manage')->first());
    }

    public function test_the_catalog_document_lists_both_keys(): void
    {
        $doc = file_get_contents(base_path('docs/spec/08-foundation.md'));

        $this->assertStringContainsString('`rota.view`', $doc);
        $this->assertStringContainsString('`rota.manage`', $doc);
    }

    public function test_an_administrator_reaches_the_editor(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/rota')->assertOk();
    }

    public function test_a_chief_resident_is_refused_the_editor_by_default(): void
    {
        // Owner decision 2 (2026-08-10), reversing the 2026-08-09 decision P1d-1 shipped:
        // rota.manage defaults to Administrator ONLY. Chief Resident reaches the editor when a
        // department grants it — see the grant case below, which proves that path end to end.
        $chief = User::factory()->create(['position' => 5]);

        $this->actingAs($chief)->get('/admin/rota')->assertForbidden();
    }

    public function test_a_resident_is_refused_the_editor(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/rota')->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/rota')->assertRedirect('/login');
    }

    public function test_every_seeded_position_holds_rota_view_by_default(): void
    {
        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position]);

            $this->assertTrue(
                \App\Support\AccessControl::allows($user, 'rota.view'),
                "position {$position} should hold rota.view"
            );
        }
    }

    public function test_only_an_administrator_holds_rota_manage_by_default(): void
    {
        foreach ([2, 3, 4, 5] as $position) {
            $this->assertFalse(
                \App\Support\AccessControl::allows(User::factory()->create(['position' => $position]), 'rota.manage'),
                "position {$position} must not hold rota.manage by default (owner decision 2, 2026-08-10)"
            );
        }

        $this->assertTrue(
            \App\Support\AccessControl::allows(User::factory()->create(['position' => 0]), 'rota.manage')
        );
    }

    /**
     * The other half of owner decision 2: "an administrator grants it per department from the
     * Access Control screen". A test that only asserts the refusal proves the default and says
     * nothing about whether the documented remedy works — which is the part a department will
     * actually use.
     */
    public function test_an_administrator_can_grant_rota_manage_to_chief_resident_from_the_screen(): void
    {
        $chief = User::factory()->create(['position' => 5]);
        $this->actingAs($chief)->get('/admin/rota')->assertForbidden();

        $admin = User::factory()->create(['position' => 0]);
        $capability = Capability::where('key', 'rota.manage')->firstOrFail();

        // Through the real endpoint, submitting the whole matrix the screen submits — never by
        // inserting a role_capabilities row directly, which would prove nothing about the screen.
        $roles = [];
        foreach ([0, 2, 3, 4, 5] as $position) {
            $roles[$position] = RoleCapability::where('position', $position)->pluck('capability_id')->all();
        }
        $roles[5][] = $capability->id;

        // PUT, not PATCH — `routes/web.php:94` registers this as
        // `Route::put('/access-control/roles', ...)->name('access-control.roles')`. Verified
        // against the router rather than assumed; a wrong verb here 405s and reads like an
        // authorization bug.
        $this->actingAs($admin)->put('/admin/access-control/roles', ['roles' => $roles])
            ->assertRedirect();

        $this->actingAs($chief)->get('/admin/rota')->assertOk();
    }

    public function test_the_retired_nurse_position_gains_no_default(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->assertFalse(\App\Support\AccessControl::allows($nurse, 'rota.view'));
        $this->assertFalse(\App\Support\AccessControl::allows($nurse, 'rota.manage'));
    }

    /**
     * `rota.view` is seeded for EVERY authenticated position, so anything reachable with it is
     * reachable by the whole department. Asserted over the ROUTER rather than as a list of 403
     * cases, because a hand-written list only covers the routes somebody remembered to add to it
     * — and the failure this guards against is a future PR hanging a write endpoint off the read
     * group, which no enumerated 403 case would ever see.
     *
     * Both shapes ship: this one, and the per-route refusals above. They fail for different
     * reasons and neither subsumes the other.
     */
    public function test_every_route_behind_cap_rota_view_is_a_get(): void
    {
        $offenders = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! in_array('cap:rota.view', $route->gatherMiddleware(), true)) {
                continue;
            }

            if ($route->methods() !== ['GET', 'HEAD']) {
                $offenders[] = $route->uri().' allows '.implode(',', $route->methods());
            }
        }

        $this->assertSame([], $offenders,
            "A write route behind cap:rota.view would be writable by every member of the department.\n"
            .implode("\n", $offenders));
    }

    /**
     * The other half of the router assertion, and the reason the one above cannot be trusted
     * alone: a guard that iterates a set is vacuously green when the set is empty. If the
     * `cap:rota.view` group is ever deleted or renamed, this fails and the GET-only assertion
     * does not.
     */
    public function test_the_read_view_route_is_actually_registered_behind_cap_rota_view(): void
    {
        $matched = array_filter(
            iterator_to_array(\Illuminate\Support\Facades\Route::getRoutes()),
            fn ($route): bool => in_array('cap:rota.view', $route->gatherMiddleware(), true),
        );

        $this->assertNotEmpty($matched, 'no route sits behind cap:rota.view — MR-05 has no read view');
    }

    public function test_the_editor_route_is_not_under_the_endorsement_prefix(): void
    {
        // Unit::RESERVED_CODES is derived from routes under `endorsement/` by
        // ReservedUnitCodesTest, bidirectionally. A rota route under that prefix would demand a
        // matching reserved code in the same commit; this one deliberately avoids the question.
        $this->assertNotContains('ROTA', \App\Models\Unit::RESERVED_CODES);
    }

    public function test_nothing_in_the_rota_infers_on_call_eligibility(): void
    {
        // Owner decision 1: MR-04 is Stage 2. It has nothing to drive — slots, call rosters and
        // per-person include/exclude overrides do not exist. This guard pins the absence so a
        // later plan reaching for "the rota already knows who is eligible" fails the build
        // instead of shipping half of a requirement.
        $offenders = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach (['off_roster', 'offRoster', 'callEligib', 'call_eligib'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = str_replace('\\', '/', $file->getRelativePathname()).": {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders, 'MR-04 is Stage 2 (P1d owner decision 1) — see the plan.');
    }
}
