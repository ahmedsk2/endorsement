<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The department checklist's SCREEN (Munawib ST-01/ST-02, P1e Decision D and E) — `/admin/setup`,
 * `cap:structure.manage`, one GET and nothing else.
 *
 * THE NAME IS TAKEN, AND THAT IS WHAT THIS FILE GUARDS FIRST. `App\Http\Controllers\
 * SetupController`, `resources/js/Pages/Setup.vue`, `GET|POST /setup`, `setup.show`/`setup.complete`,
 * `App\Http\Middleware\RequireSetup` and `users.setup_completed_at` all belong to the per-USER
 * first-login two-factor flow, which is a different thing entirely from a per-DEPARTMENT
 * configuration checklist. Decision E names this one `DepartmentSetup` throughout; the collision
 * test below fails loudly if a later refactor decides the two are "the same screen really".
 *
 * `RequireSetup` IS NOT MODIFIED AND `/admin/setup` IS NOT ON ITS ALLOWED LIST. The consequence is
 * intended rather than tolerated: an administrator finishes their own second factor before they
 * configure a system that holds children's records. The test that pins it exists so a reader who
 * finds the redirect surprising reads a test saying it is the point, instead of widening an
 * allow-list whose whole job is to make sure the person configuring a PHI system has 2FA on.
 *
 * GET-ONLY, OVER THE ROUTER, WITH A VACUITY TWIN. The checklist is derived and stores nothing
 * (Decision D), so a POST under `admin/setup` would mean somebody had started keeping wizard state
 * — the second definition of one fact this codebase has now refused four times. The sweep is by
 * URI prefix rather than by capability, because `cap:structure.manage` legitimately guards the
 * write endpoints of every structure screen; and it carries the twin because P1e-1 Task 4's DELETE
 * sweep passed on its first red run against an empty route set.
 */
class DepartmentSetupScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return $this->actingAs($this->admin)->get('/admin/setup')->viewData('page')['props'];
    }

    // --- Who reaches it ---------------------------------------------------------------------

    public function test_a_structure_manager_reaches_the_checklist_and_a_resident_does_not(): void
    {
        $this->actingAs($this->admin)->get('/admin/setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/DepartmentSetup')
                ->has('steps', 9)
                ->has('later', 2)
            );

        $this->actingAs(User::factory()->create(['position' => 4]))
            ->get('/admin/setup')
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/setup')->assertRedirect('/login');
    }

    // --- It writes nothing, and cannot grow a way to -----------------------------------------

    /**
     * Over the ROUTER, so it holds for routes nobody has written yet. A hand-written list of 405
     * cases only covers the verbs somebody remembered, and the failure this guards is a future PR
     * hanging a "save my progress" endpoint off the checklist.
     */
    public function test_every_route_under_admin_setup_is_a_get(): void
    {
        $offenders = [];

        foreach ($this->checklistRoutes() as $route) {
            if ($route->methods() !== ['GET', 'HEAD']) {
                $offenders[] = $route->uri().' allows '.implode(',', $route->methods());
            }
        }

        $this->assertSame([], $offenders,
            "The department checklist is derived and stores nothing (P1e Decision D). A write route "
            ."under admin/setup would mean wizard state had started being kept somewhere.\n"
            .implode("\n", $offenders));
    }

    /**
     * THE VACUITY TWIN, and it is not optional: the sweep above iterates an empty set — and passes
     * — the moment the route is renamed or removed. P1e-1 Task 4 shipped exactly that mistake and
     * only caught it because the red run was watched.
     */
    public function test_the_checklist_route_is_registered_behind_the_structure_capability(): void
    {
        $routes = $this->checklistRoutes();

        $this->assertCount(1, $routes, 'exactly one route lives under admin/setup: the checklist');

        $route = $routes[0];

        $this->assertSame('admin.setup', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('cap:structure.manage', $route->gatherMiddleware());
    }

    /**
     * Decision D's whole claim, asserted at the SCREEN rather than only at the projection: opening
     * the checklist is a read. Every table in the schema is counted around the request — derived
     * from `Schema::getTableListing()` rather than a hand-written list, which is the thing that
     * goes stale, and compared WHOLE because SQLite returns those names schema-qualified
     * (`main.app_settings`), so a named-key assertion would find nothing on either side and pass
     * forever.
     */
    public function test_opening_the_checklist_writes_nothing_anywhere(): void
    {
        $before = $this->rowCounts();

        $this->actingAs($this->admin)->get('/admin/setup')->assertOk();

        $this->assertSame($before, $this->rowCounts(),
            'opening the department checklist wrote something. It is derived and stores nothing — '
            .'no table, no column, no app_settings key, no session key.');
    }

    // --- It is not the per-user 2FA flow ------------------------------------------------------

    public function test_the_department_checklist_does_not_collide_with_the_per_user_setup_flow(): void
    {
        $department = Route::getRoutes()->getByName('admin.setup');
        $perUser = Route::getRoutes()->getByName('setup.show');

        $this->assertNotNull($department, 'admin.setup must exist');
        $this->assertNotNull($perUser, 'setup.show must still exist, untouched');

        $this->assertNotSame($perUser->getActionName(), $department->getActionName(),
            'the per-user two-factor flow and the department checklist are different things and '
            .'must not be served by one controller');

        $this->assertSame('setup', $perUser->uri());
        $this->assertSame('admin/setup', $department->uri());

        // And the per-user flow still renders its own page, unchanged.
        $fresh = User::factory()->create(['position' => 4, 'setup_completed_at' => null]);

        $this->actingAs($fresh)->get('/setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Setup'));
    }

    /**
     * `RequireSetup` is deliberately NOT widened for `/admin/setup`. Configure a system holding
     * children's records only after your own second factor is on.
     */
    public function test_an_administrator_who_has_not_done_their_own_two_factor_setup_is_redirected_away(): void
    {
        $unfinished = User::factory()->create(['position' => 0, 'setup_completed_at' => null]);

        $this->actingAs($unfinished)->get('/admin/setup')->assertRedirect('/setup');
    }

    // --- The props ----------------------------------------------------------------------------

    /**
     * A checklist pointing at a screen that no longer exists is worse than no checklist: it sends
     * an administrator to a 404 in the middle of configuring a department. Every step's `route` is
     * resolved over the router and every `url` is compared against what the router itself builds.
     */
    public function test_every_step_links_to_a_registered_route(): void
    {
        $props = $this->props();

        $this->assertNotSame([], $props['steps']);

        foreach ($props['steps'] as $step) {
            $this->assertTrue(Route::has($step['route']), "step {$step['key']} names an unregistered route {$step['route']}");
            $this->assertSame(route($step['route'], absolute: false), $step['url'],
                "step {$step['key']} links somewhere the router does not agree with");
        }
    }

    /**
     * REVIEW steps reach the screen as review steps — never tickable, always carrying the current
     * values, because a default is indistinguishable from a reviewed default and a checkbox would
     * claim otherwise.
     */
    public function test_a_review_step_reaches_the_screen_with_its_values_and_never_as_done(): void
    {
        $steps = [];

        foreach ($this->props()['steps'] as $step) {
            $steps[$step['key']] = $step;
        }

        foreach (['profile', 'calendar', 'holidays'] as $key) {
            $this->assertSame('review', $steps[$key]['kind']);
            $this->assertFalse($steps[$key]['done'], "the {$key} step must never be reported done");
            $this->assertNotSame('', trim((string) $steps[$key]['summary']));
        }

        $this->assertSame('required', $steps['levels']['kind']);
        $this->assertTrue($steps['levels']['done'], 'ReferenceSeeder seeds the training ladder');
    }

    /**
     * The P2/P3 items are STATED, never rendered as two permanently unticked boxes an
     * administrator will try to click. No route, no url, no `done` — so there is nothing for the
     * screen to link or tick even by accident.
     */
    public function test_the_later_items_carry_no_route_no_url_and_no_done(): void
    {
        $later = $this->props()['later'];

        $this->assertCount(2, $later);

        foreach ($later as $item) {
            $this->assertArrayNotHasKey('route', $item);
            $this->assertArrayNotHasKey('url', $item);
            $this->assertArrayNotHasKey('done', $item);
            $this->assertNotSame('', trim((string) $item['stage']));
            $this->assertNotSame('', trim((string) $item['summary']));
        }
    }

    // --- Helpers -------------------------------------------------------------------------------

    /** @return list<\Illuminate\Routing\Route> */
    private function checklistRoutes(): array
    {
        $matched = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'admin/setup' || str_starts_with($route->uri(), 'admin/setup/')) {
                $matched[] = $route;
            }
        }

        return $matched;
    }

    /** @return array<string, int> every table in the schema, counted. */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (Schema::getTableListing() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
