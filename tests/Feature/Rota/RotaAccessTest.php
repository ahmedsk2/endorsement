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

    /**
     * MR-04 RESTATED OVER THE FILES THAT MAKE THE INFERENCE TEMPTING (P1d-2 Task 12).
     *
     * The scan above is wide and shallow: four identifier-shaped needles over the whole of `app/`.
     * This one is narrow and deep, and P1d-2 is why it exists. An AVAILABILITY SUMMARY is
     * precisely the shape somebody would reach for to answer *"who can take call in Block 11?"*,
     * a BULK FILL is how they would write the answer across a year, and an IMPORTER is how they
     * would load one from a spreadsheet. So the absence is asserted over `App\Support\Rota` in
     * full — `AvailabilitySummary`, `RotaFill`, `RotaImport` and the rest — plus the rota's
     * controllers, its form requests and its screens, rather than only over the files that
     * predate the temptation.
     *
     * MR-04 has nothing to drive: there are no slots, no call roster, and no per-person
     * include/exclude override. P1d-2 records the hook and builds none of it.
     *
     * IT SCANS CODE, NOT PROSE, AND THAT IS A DELIBERATE DEPARTURE FROM FINDING 14.
     * `CalendarIsTheOnlyConverterTest` matches docblocks on purpose and the remedy there is to
     * write around the call shape in prose. That remedy is unavailable here: three of these files
     * open with a paragraph stating that they must never become an eligibility computation, which
     * is the exact sentence a future implementer most needs to read — and `eligib` as a raw
     * substring needle would fail the build for writing it down. A guard that punishes the
     * documentation of the rule it enforces trains people to delete the documentation, so
     * comments are stripped before the scan and {@see
     * test_the_scan_strips_comments_and_still_sees_the_code} proves both halves of that.
     */
    public function test_nothing_in_the_rota_namespace_infers_on_call_eligibility(): void
    {
        $files = $this->rotaSurfaceFiles();

        // Non-vacuity: a guard that iterates an empty set is green for the wrong reason, and a
        // renamed file silently leaving the set is exactly how one gets there.
        $this->assertGreaterThanOrEqual(15, count($files), 'the rota surface scan lost files');

        $offenders = [];

        foreach ($files as $path) {
            // Lower-cased, so the match is CASE-INSENSITIVE. That is only safe because comments
            // are gone: over raw source it would fire on every "ELIGIBILITY" in a docblock. Over
            // code it is the difference between catching `off_roster` alone and also catching
            // `isEligibleForCall()`, `$offRoster` and `OnCallRoster` — the shapes an actual
            // implementation would take, none of which a fixed-case substring list would see.
            $code = mb_strtolower(self::sourceWithoutComments($path));
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));

            foreach (['off_roster', 'offRoster', 'callEligib', 'call_eligib', 'eligib', 'on_call', 'onCall', 'callRoster'] as $needle) {
                if (str_contains($code, mb_strtolower($needle))) {
                    $offenders[] = "{$relative}: {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "MR-04 (the rota driving on-call eligibility) is Stage 2 — P1d owner decision 1.\n"
            ."Nothing in the rota infers eligibility: no off-roster flag, no call-roster derivation,\n"
            ."no per-person include/exclude override. P1d-2 records the hook and builds none of it.\n"
            .implode("\n", $offenders));
    }

    /**
     * The scan above is only as good as its stripper, in both directions, so both are asserted
     * against a REAL file rather than a contrived string.
     *
     *  - It must REMOVE prose: `AvailabilitySummary`'s docblock says "eligibility" out loud, and
     *    the scan must not fire on it.
     *  - It must KEEP code: if the stripper over-reached and returned an empty (or comment-free
     *    but code-free) file, every needle would miss and the guard would be silently disabled —
     *    the same green as a clean tree.
     */
    public function test_the_scan_strips_comments_and_still_sees_the_code(): void
    {
        $path = app_path('Support/Rota/AvailabilitySummary.php');

        $raw = (string) file_get_contents($path);
        $code = self::sourceWithoutComments($path);

        $this->assertStringContainsString('eligibility', $raw,
            'the file this test calibrates against no longer contains the prose it was chosen for');
        $this->assertStringNotContainsStringIgnoringCase('eligib', $code, 'the comment stripper left a docblock behind');
        $this->assertStringContainsString('final class AvailabilitySummary', $code,
            'the comment stripper ate the code — every needle would miss and the guard would be vacuous');

        $vue = self::sourceWithoutComments(resource_path('js/Pages/Admin/RotaImport.vue'));

        $this->assertStringNotContainsString('MR-06', $vue, 'the .vue comment stripper left a comment behind');
        $this->assertStringContainsString('data-testid="import-commit"', $vue,
            'the .vue comment stripper ate the markup');
    }

    /**
     * Every rota surface, by path. The `App\Support\Rota` half is a glob so a class added there
     * joins the scan without anybody remembering to; the rest is explicit and each entry is
     * asserted to EXIST, because a stale path in a guard's list is a guard that quietly stopped
     * covering something (`CsvIsTheOnlyReaderWriterTest` keeps the same discipline).
     *
     * @return list<string>
     */
    private function rotaSurfaceFiles(): array
    {
        $files = glob(app_path('Support/Rota/*.php')) ?: [];

        $this->assertGreaterThanOrEqual(9, count($files), 'App\Support\Rota is not being globbed');

        $named = [
            'app/Http/Controllers/Admin/MasterRotaController.php',
            'app/Http/Controllers/Admin/RotaImportController.php',
            'app/Http/Controllers/RotaController.php',
            'app/Http/Requests/Admin/RotaCellRequest.php',
            'app/Http/Requests/Admin/RotaFillRequest.php',
            'app/Http/Requests/Admin/RotaImportRequest.php',
            'app/Http/Requests/Admin/VacationRequest.php',
            'resources/js/Pages/Admin/MasterRota.vue',
            'resources/js/Pages/Admin/RotaImport.vue',
            'resources/js/Pages/Rota.vue',
            'resources/js/Components/AvailabilityPanel.vue',
        ];

        foreach ($named as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, "The MR-04 scan names {$relative}, which is gone — prune or correct the list.");
            $files[] = $path;
        }

        return $files;
    }

    /**
     * ONE stripper, shared with `ClinicHooksTest` (P1e Task 6) — it used to be a private method
     * here, and a second copy over there would have been two definitions of one fact, drifting the
     * first time either learned about a new comment shape. Its own docblock carries the
     * conservative-by-design argument; both callers pin it in both directions against real files of
     * their own choosing, because a proof about one file's comments is not a proof about another's.
     */
    private static function sourceWithoutComments(string $path): string
    {
        return \Tests\Support\SourceScanner::withoutComments($path);
    }
}
