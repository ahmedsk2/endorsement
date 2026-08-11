<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Setup\DepartmentSetup;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Set up this department (Munawib ST-01/ST-02, P1e Decisions D and E) — `/admin/setup`,
 * `cap:structure.manage`, one GET.
 *
 * A PATH THROUGH SCREENS THAT ALREADY EXIST, NOT A SECOND SET OF THEM. ST-01 lists nine steps and
 * ST-02 says every one is revisitable in Settings; read strictly, those two sentences together say
 * the wizard is a path rather than a place. Each step links to the screen that already owns it, so
 * "revisitable" is satisfied by construction instead of by a second implementation of eleven admin
 * pages that would then have to be kept in step with the first.
 *
 * IT HOLDS NO STATE, ANYWHERE — no column, no table, no `app_settings` key, no session key.
 * `App\Support\Setup\DepartmentSetup` derives the whole checklist from the data every time it is
 * asked, and this controller adds nothing to that but a URL per step. Abandonment is therefore a
 * non-event: an administrator closes the tab and the department is exactly as configured as it
 * was, and reopening this page recomputes. There is nothing to resume, expire or garbage-collect,
 * and `DepartmentSetupScreenTest::test_opening_the_checklist_writes_nothing_anywhere` counts every
 * table in the schema around a request to pin it.
 *
 * THE NAME IS TAKEN, AND THAT IS WHY THIS CLASS IS SPELLED THE WAY IT IS. `SetupController`,
 * `Setup.vue`, `/setup`, `setup.show`, `setup.complete`, `RequireSetup` and
 * `users.setup_completed_at` all belong to the per-USER first-login two-factor flow. None of them
 * is touched, and `/admin/setup` is deliberately NOT added to `RequireSetup`'s allowed paths: an
 * administrator finishes their own second factor before configuring a system that holds children's
 * records. The redirect that follows is intended, and there is a test saying so, because the
 * obvious "fix" is to widen the very allow-list that exists to prevent it.
 *
 * THE `route` NAME IS RESOLVED HERE RATHER THAN ON THE CLIENT. The projection stores a route NAME;
 * the screen needs a path. Building it here means the router is the one authority on where a step
 * goes — a checklist that sent an administrator to a 404 halfway through configuring a department
 * would be worse than no checklist — and `test_every_step_links_to_a_registered_route` compares
 * every emitted URL against what the router itself builds.
 *
 * `later` PASSES THROUGH UNTOUCHED, AND GAINS NO URL. The Stage 2/Stage 3 items carry prose and a
 * stage label with no route and no `done` key, so there is nothing here for the screen to link or
 * tick even by accident. They are stated rather than rendered as two permanently unticked boxes.
 *
 * NO CALENDAR FLUSH, AND NOTHING HERE TRIPS THE GUARD THAT WOULD ASK FOR ONE: this class reads the
 * projection, never the department's configuration row, so it matches none of
 * `CalendarWritersFlushTest`'s needles and needs no allow-list entry. `DepartmentSetup` itself is
 * on that list, with its reason at the site.
 */
class DepartmentSetupController extends Controller
{
    public function index(): Response
    {
        $checklist = DepartmentSetup::steps();

        return Inertia::render('Admin/DepartmentSetup', [
            'steps' => array_map(
                static fn (array $step): array => [...$step, 'url' => route($step['route'], absolute: false)],
                $checklist['steps'],
            ),
            'later' => $checklist['later'],
        ]);
    }
}
