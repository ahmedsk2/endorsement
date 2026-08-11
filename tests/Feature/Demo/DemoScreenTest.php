<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\Institution;
use App\Models\Period;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\TableCounts;
use Tests\TestCase;

/**
 * ST-05's *"one-click"* half, and the confirmation before the other one (P1e Task 14) —
 * `/admin/structure/demo`, `cap:structure.manage`, one GET and two writes.
 *
 * THE GET IS THE PREVIEW. Neither half of this screen takes any operator input — there is nothing
 * to upload, nobody to select, no cell to pick — so the state both actions are pinned to is
 * computed when the page is rendered and posted back with the confirmation. A separate "preview"
 * POST would be a button whose only job is to show what the page already shows, and it would be
 * one boolean away from the destructive path. Both pins therefore travel as props, and neither
 * confirm control can be reached without the request that computed one.
 *
 * BOTH PINS ARE `App\Support\Rota\StatePin` (invariant 12), not a second definition of what a
 * preview-then-confirm commit is pinned to. Two of the tests below are what earn that: each plants
 * a change that the operation's own re-derivation CANNOT catch, and watches the pin catch it.
 *
 *  - Creation: the department generates its academic year between the preview and the confirm. The
 *    preview said "the demo will generate its own periods"; without the pin the demo silently
 *    reuses the real ones instead, and `create()` — which re-derives everything and is right to —
 *    succeeds without a word.
 *  - Removal: the demo is removed and re-created by somebody else between the preview and the
 *    confirm. Without the pin the DELETE removes a department this operator never previewed, and
 *    every table count still returns to where it started, so nothing anywhere would notice.
 *
 * IT MAY BE PRESSED ON THE LIVE INSTANCE (owner ruling, 2026-08-11), which is what most of the
 * rest of this file is about: a resident cannot reach it, removal is refused whole the moment real
 * work leans on the demo, the refusal names the tables and counts HOLDING it rather than merely
 * saying no, and the word is typed rather than a button clicked — this is a hard delete with no
 * undo anywhere in the product.
 */
class DemoScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $this->admin = User::factory()->create(['position' => 0]);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return $this->actingAs($this->admin)
            ->get('/admin/structure/demo')
            ->viewData('page')['props'];
    }

    /**
     * The whole-schema snapshot MINUS `audit_log`. "Nothing was created" and "nothing was deleted"
     * are the claims; "nothing was written" is not — a refused operator action is exactly what the
     * hash-chained trail exists to record.
     *
     * @return array<string, int>
     */
    private function countsWithoutAudit(): array
    {
        $counts = TableCounts::snapshot();

        unset($counts[TableCounts::qualify('audit_log')]);

        return $counts;
    }

    /** A real handover on the demo's own unit — the training session that makes removal refuse. */
    private function realHandoverOnTheDemoUnit(): void
    {
        Handover::create([
            'unit_id' => Unit::findByCode(DemoDepartment::UNIT_CODE)?->getKey(),
            'handover_date' => Calendar::todayYmd(),
            'bed' => '1', 'mrn' => 'M-1', 'patient_name' => 'A Child',
        ]);
    }

    // --- Who reaches it -----------------------------------------------------------------------

    public function test_only_a_structure_manager_reaches_it(): void
    {
        $this->actingAs($this->admin)->get('/admin/structure/demo')->assertOk();

        $resident = User::factory()->create(['position' => 4]);

        // 403, never a redirect to a half-rendered page: a resident who cannot manage structure
        // must be refused rather than shown a screen with the controls quietly missing.
        $this->actingAs($resident)->get('/admin/structure/demo')->assertForbidden();
        $this->actingAs($resident)->post('/admin/structure/demo')->assertForbidden();
        $this->actingAs($resident)->delete('/admin/structure/demo')->assertForbidden();

        $this->assertFalse(DemoLedger::has(), 'a refused resident created nothing');
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/demo')->assertRedirect('/login');
    }

    // --- The routes ---------------------------------------------------------------------------

    /**
     * THE VACUITY TWIN for the verb sweep below, and it is not optional: a sweep over an empty
     * route set passes. P1e-1 Task 4 and Task 10 each shipped exactly that mistake and caught it
     * only because the red run was watched.
     */
    public function test_the_three_routes_are_registered_behind_the_structure_capability(): void
    {
        $routes = $this->demoRoutes();

        $this->assertCount(3, $routes, 'one GET (the preview), one POST (create) and one DELETE');

        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('cap:structure.manage', $route->gatherMiddleware());
        }

        $this->assertNotNull(Route::getRoutes()->getByName('admin.structure.demo'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.structure.demo.store'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.structure.demo.destroy'));
    }

    /**
     * Creating and removing are POST and DELETE + CSRF, never a GET — over the ROUTER, so it holds
     * for routes nobody has written yet. A GET that created a demo department would be one
     * link-preview fetch away from seeding a live clinical instance.
     */
    public function test_creating_and_removing_are_writes_and_never_a_get(): void
    {
        $offenders = [];

        foreach ($this->demoRoutes() as $route) {
            $methods = $route->methods();

            $write = in_array('POST', $methods, true) || in_array('DELETE', $methods, true);

            if ($write && (in_array('GET', $methods, true) || in_array('HEAD', $methods, true))) {
                $offenders[] = $route->uri().' allows '.implode(',', $methods);
            }

            // The `web` group is what puts CSRF on the write verbs; a route registered outside it
            // would take the same POST with no token at all.
            if ($write && ! in_array('web', $route->gatherMiddleware(), true)) {
                $offenders[] = $route->uri().' is outside the web group (no CSRF)';
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));

        // And at runtime: the verbs nobody registered are a plain 405, not a silent no-op.
        $this->actingAs($this->admin)->put('/admin/structure/demo')->assertStatus(405);
    }

    // --- Creating -----------------------------------------------------------------------------

    public function test_the_screen_states_what_would_be_created_before_anything_is(): void
    {
        $props = $this->props();

        $this->assertNull($props['demo'], 'no demo department exists yet');
        $this->assertSame([], $props['plan']['refusals'], 'nothing stands in the way of creating one');
        $this->assertNotSame('', $props['plan']['pin']);

        // A freshly seeded department has a training ladder and no periods, so a demo here does
        // everything a demo can do and there is nothing to warn about. Asserted rather than
        // skipped over: an empty list must mean "nothing to say", not "the projection is broken".
        $this->assertSame([], $props['plan']['skipped']);

        // And the moment the department has an academic year of its own, the preview says the demo
        // will use it rather than generating a second one beside it — which is the single thing
        // about this operation that varies by department, and the thing the pin below is over.
        Period::create([
            'institution_id' => Institution::query()->value('id'),
            'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Block 1',
            'starts_on' => Calendar::addDays(Calendar::todayYmd(), -7)->format(Calendar::YMD),
            'ends_on' => Calendar::addDays(Calendar::todayYmd(), 21)->format(Calendar::YMD),
        ]);

        $this->assertStringContainsString('period', strtolower(implode(' ', $this->props()['plan']['skipped'])));
    }

    public function test_creating_reports_what_it_skipped_rather_than_skipping_it_silently(): void
    {
        // A department that already generated its academic year: the demo must NOT add a second
        // one beside it, and the operator must be told that is what happened.
        Period::create([
            'institution_id' => Institution::query()->value('id'),
            'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Block 1',
            'starts_on' => Calendar::addDays(Calendar::todayYmd(), -7)->format(Calendar::YMD),
            'ends_on' => Calendar::addDays(Calendar::todayYmd(), 21)->format(Calendar::YMD),
        ]);

        $props = $this->props();

        $this->actingAs($this->admin)
            ->post('/admin/structure/demo', ['pin' => $props['plan']['pin']])
            ->assertRedirect();

        $this->assertTrue(DemoLedger::has());

        // The result reaches the screen through the shared flash channel — a session key no
        // `share()` names is invisible to every page in the app, which this codebase has now paid
        // for three times.
        $result = $this->props()['flash']['demo_result'];

        $this->assertSame(1, count(DemoLedger::batches()));
        $this->assertGreaterThan(0, $result['rows']);
        $this->assertStringContainsString('period', strtolower(implode(' ', $result['skipped'])));
    }

    public function test_the_create_control_is_refused_when_a_demo_already_exists(): void
    {
        DemoDepartment::create();

        $props = $this->props();

        $this->assertNotSame([], $props['plan']['refusals'], 'the screen says why it cannot be pressed');

        $before = $this->countsWithoutAudit();

        $this->actingAs($this->admin)
            ->post('/admin/structure/demo', ['pin' => $props['plan']['pin']])
            ->assertSessionHasErrors('create_pin');

        $this->assertSame($before, $this->countsWithoutAudit(), 'a refused create wrote nothing');
        $this->assertSame(1, count(DemoLedger::batches()));
    }

    /**
     * THE CREATE PIN, PROVED ON THE ONE CHANGE `create()`'s OWN RE-DERIVATION CANNOT CATCH.
     *
     * The preview says "this department has no periods, so the demo will generate its own academic
     * year". Somebody generates the department's real year in another tab. `create()` re-derives —
     * correctly — and quietly places the demo rota in the REAL year instead, which is a different
     * department from the one this operator approved, with no error anywhere. The pin is the only
     * thing in the system that notices.
     */
    public function test_creating_is_pinned_to_what_the_operator_saw(): void
    {
        $stale = $this->props()['plan']['pin'];

        Period::create([
            'institution_id' => Institution::query()->value('id'),
            'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Block 1',
            'starts_on' => Calendar::addDays(Calendar::todayYmd(), -7)->format(Calendar::YMD),
            'ends_on' => Calendar::addDays(Calendar::todayYmd(), 21)->format(Calendar::YMD),
        ]);

        $before = $this->countsWithoutAudit();

        $this->actingAs($this->admin)
            ->post('/admin/structure/demo', ['pin' => $stale])
            ->assertSessionHasErrors('create_pin');

        $this->assertFalse(DemoLedger::has(), 'a stale preview created nothing at all');
        $this->assertSame($before, $this->countsWithoutAudit());

        // And the fresh preview goes through, which is what makes the refusal a re-read rather
        // than a dead end.
        $this->actingAs($this->admin)
            ->post('/admin/structure/demo', ['pin' => $this->props()['plan']['pin']])
            ->assertSessionHasNoErrors();

        $this->assertTrue(DemoLedger::has());
    }

    // --- Removing -----------------------------------------------------------------------------

    public function test_removal_shows_the_preflight_before_it_asks_for_confirmation(): void
    {
        DemoDepartment::create();

        $this->realHandoverOnTheDemoUnit();

        $demo = $this->props()['demo'];

        $this->assertSame([['table', 'count', 'remedy']], array_map('array_keys', $demo['blocked']));
        $this->assertSame([['table' => 'handovers', 'count' => 1]], array_map(
            static fn (array $row): array => ['table' => $row['table'], 'count' => $row['count']],
            $demo['blocked'],
        ), 'the screen must show WHAT is holding the removal, not only that it refused');

        // …and what to do about it, per table. "Delete them or point them somewhere real" was the
        // one sentence every blocker used to get, and it is wrong for half of them.
        $this->assertStringContainsString('Merge', (string) $demo['blocked'][0]['remedy']);

        // …and the composition of what would go, so "remove" is not a number nobody can check.
        $this->assertContains(['table' => 'units', 'count' => 1], $demo['counts']);
        $this->assertGreaterThan(0, $demo['total']);
    }

    public function test_the_preflight_on_the_screen_names_tables_and_counts_and_never_a_name(): void
    {
        DemoDepartment::create();
        $this->realHandoverOnTheDemoUnit();

        $encoded = json_encode($this->props()['demo']);

        $this->assertStringContainsString('handovers', (string) $encoded);

        // Invariant 9: no PHI and no personal data anywhere near this payload.
        foreach (['A Child', 'M-1', 'Demo Resident One', 'demo.invalid'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $encoded,
                "the pre-flight payload names [{$forbidden}]; it carries tables and counts only");
        }
    }

    public function test_removal_requires_the_word_typed(): void
    {
        DemoDepartment::create();

        $demo = $this->props()['demo'];
        $before = $this->countsWithoutAudit();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/demo', ['pin' => $demo['pin'], 'confirm_demo' => 'yes'])
            ->assertSessionHasErrors('confirm_demo');

        $this->assertSame($before, $this->countsWithoutAudit(), 'a mistyped confirmation deleted nothing');

        $this->actingAs($this->admin)
            ->delete('/admin/structure/demo', [
                'pin' => $demo['pin'],
                'confirm_demo' => DemoDepartment::UNIT_CODE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(DemoLedger::has());
    }

    public function test_a_blocked_removal_is_refused_whole_and_the_screen_says_what_holds_it(): void
    {
        DemoDepartment::create();

        $demo = $this->props()['demo'];

        $this->realHandoverOnTheDemoUnit();

        $before = $this->countsWithoutAudit();

        $response = $this->actingAs($this->admin)->delete('/admin/structure/demo', [
            'pin' => $demo['pin'],
            'confirm_demo' => DemoDepartment::UNIT_CODE,
        ]);

        $response->assertSessionHasErrors();

        $this->assertSame($before, $this->countsWithoutAudit(),
            'a refused removal is refused WHOLE — nothing was deleted');
        $this->assertTrue(DemoLedger::has());

        // And the screen the operator lands back on shows the blocker itself, freshly read,
        // rather than only an error string.
        $this->assertSame([['table' => 'handovers', 'count' => 1]], array_map(
            static fn (array $row): array => ['table' => $row['table'], 'count' => $row['count']],
            $this->props()['demo']['blocked'],
        ));
    }

    /**
     * THE REMOVAL PIN, PROVED ON THE ONE CHANGE `remove()`'s OWN RE-DERIVATION CANNOT CATCH.
     *
     * Two administrators. The first opens this screen. The second removes the demo and creates a
     * fresh one. The first presses Remove. Without the pin that DELETE removes a department the
     * operator never previewed — and every table returns to its pre-seed count, so neither the
     * round-trip proof nor the pre-flight nor the audit trail would show anything amiss.
     */
    public function test_removal_is_pinned_to_what_the_operator_saw(): void
    {
        $first = DemoDepartment::create();

        $stale = $this->props()['demo']['pin'];

        DemoDepartment::remove($first['batch']);
        $second = DemoDepartment::create();

        $before = $this->countsWithoutAudit();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/demo', [
                'pin' => $stale,
                'confirm_demo' => DemoDepartment::UNIT_CODE,
            ])
            ->assertSessionHasErrors('remove_pin');

        $this->assertSame($before, $this->countsWithoutAudit(), 'the second batch is untouched');
        $this->assertSame([$second['batch']], DemoLedger::batches());
    }

    /** The pin also catches the world moving under a removal that was previewed as clear. */
    public function test_a_removal_previewed_as_clear_is_refused_once_real_work_lands_on_it(): void
    {
        DemoDepartment::create();

        $clear = $this->props()['demo'];

        $this->assertSame([], $clear['blocked'], 'previewed with nothing in the way');

        $this->realHandoverOnTheDemoUnit();

        $before = $this->countsWithoutAudit();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/demo', [
                'pin' => $clear['pin'],
                'confirm_demo' => DemoDepartment::UNIT_CODE,
            ])
            ->assertSessionHasErrors('remove_pin');

        $this->assertSame($before, $this->countsWithoutAudit());
        $this->assertTrue(DemoLedger::has());
    }

    // --- The trail ----------------------------------------------------------------------------

    public function test_both_actions_write_exactly_one_audit_row_naming_the_operator(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/demo', ['pin' => $this->props()['plan']['pin']])
            ->assertSessionHasNoErrors();

        $created = AuditLog::query()->where('action', 'demo_department_create')->get();

        $this->assertCount(1, $created);
        $this->assertSame($this->admin->getKey(), $created[0]->user_id);
        $this->assertStringContainsString('rows=', (string) $created[0]->detail);

        $this->actingAs($this->admin)
            ->delete('/admin/structure/demo', [
                'pin' => $this->props()['demo']['pin'],
                'confirm_demo' => DemoDepartment::UNIT_CODE,
            ])
            ->assertSessionHasNoErrors();

        $removed = AuditLog::query()->where('action', 'demo_department_remove')->get();

        $this->assertCount(1, $removed);
        $this->assertSame($this->admin->getKey(), $removed[0]->user_id);

        // No name of any kind reaches the trail — invariant 9, asserted over both rows at once.
        foreach ([$created[0], $removed[0]] as $row) {
            $this->assertStringNotContainsString('Demo ', (string) $row->detail);
        }
    }

    public function test_a_blocked_removal_is_audited_as_its_own_action(): void
    {
        DemoDepartment::create();

        $demo = $this->props()['demo'];

        $this->realHandoverOnTheDemoUnit();

        // Straight past the pin, as the console command does: the audited refusal belongs to the
        // writer, and the screen must not be the only path that records one.
        $this->actingAs($this->admin)->delete('/admin/structure/demo', [
            'pin' => $this->props()['demo']['pin'],
            'confirm_demo' => DemoDepartment::UNIT_CODE,
        ]);

        $this->assertSame(1, AuditLog::query()->where('action', 'demo_department_remove_refused')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'demo_department_remove')->count());
        $this->assertNotSame('', (string) $demo['pin']);
    }

    // --- Helpers ------------------------------------------------------------------------------

    /** @return list<\Illuminate\Routing\Route> */
    private function demoRoutes(): array
    {
        $matched = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'admin/structure/demo' || str_starts_with($route->uri(), 'admin/structure/demo/')) {
                $matched[] = $route;
            }
        }

        return $matched;
    }
}
