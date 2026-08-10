<?php

use App\Http\Controllers\Admin\AccessControlController;
use App\Http\Controllers\Admin\CalendarSettingsController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\LevelController;
use App\Http\Controllers\Admin\MasterRotaController;
use App\Http\Controllers\Admin\PeriodController;
use App\Http\Controllers\Admin\PersonController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\RosterImportController;
use App\Http\Controllers\Admin\RotaImportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UnitMergeController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\EndorsementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RotaController;
use App\Http\Controllers\SignatureController;
use Illuminate\Support\Facades\Route;

// The root lands on the endorsement surface.
Route::get('/', fn () => redirect('/endorsement'));

/*
 * The shift-endorsement / handover module over the collapsed `handovers` table.
 * Reads are `endorsement.view`; every write is `endorsement.edit` (legacy gate [0,2,3,4] —
 * excludes Nurse). The `{date}` param is regex-pinned to Y-m-d so the literal write
 * sub-routes (/new-day, /rows) never bind as a date, and the row {handover} bindings are
 * declared apart from the {unit}/{date} reads so `rows` never binds as a unit.
 */
Route::middleware(['auth', 'throttle:clinical'])->prefix('endorsement')->name('endorsement.')->group(function () {
    // The four-unit chooser: one card per unit with today's status.
    Route::middleware('cap:endorsement.view')->get('/', [EndorsementController::class, 'root'])
        ->name('root');

    // One-tap access (the PWA's start_url): the remembered unit's current sheet.
    // Declared BEFORE the {unit} routes so `today` never binds as a unit code.
    Route::middleware('cap:endorsement.view')->get('/today', [EndorsementController::class, 'today'])
        ->name('today');

    // The missed-days view (spec §10.3) — the system's only aggregate. Its own narrow
    // capability, default Administrator-only, grantable per role or per named user.
    Route::middleware('cap:endorsement.compliance')->get('/compliance', [EndorsementController::class, 'compliance'])
        ->name('compliance');

    // Row edits (edit) — declared before the {unit}/{date} reads so `rows` never binds a unit.
    Route::middleware('cap:endorsement.edit')->group(function () {
        // {handover} deliberately stays on the DEFAULT (trashed-excluding) binding — P1c Task 7's
        // audit of every soft-deletable route binding. `deleteRow()` below IS the soft delete, so
        // an already-deleted row 404ing here is the correct case that audit's own example names:
        // you should not be able to edit (or re-delete) a soft-deleted clinical row through the
        // normal edit route. There is no restore action for a handover row.
        Route::patch('/rows/{handover}', [EndorsementController::class, 'updateRow'])->name('rows.update');
        Route::delete('/rows/{handover}', [EndorsementController::class, 'deleteRow'])->name('rows.delete');
        Route::post('/{unit}/new-day', [EndorsementController::class, 'newDay'])->name('new-day');
        Route::post('/{unit}/{date}/rows', [EndorsementController::class, 'storeRow'])
            ->where('date', '\d{4}-\d{2}-\d{2}')->name('rows.store');

        // The per-day shift attestation (legacy `validate-endorsement.php`). The reopen
        // sub-route is declared first so `reopen` never binds as part of the signoff path.
        // Reopen additionally requires `endorsement.reopen`, checked IN-CONTROLLER so the
        // 403 can name the actual active holders. Neither route below binds a HandoverSignoff
        // by route-model-binding at all — both params are plain strings ({unit}/{date}), and the
        // controller resolves the signoff row itself — so P1c Task 7's route-binding audit (the
        // gap Laravel's default binding leaves for every soft-deletable model) does not apply here.
        Route::post('/{unit}/{date}/signoff/reopen', [EndorsementController::class, 'reopenSignoff'])
            ->where('date', '\d{4}-\d{2}-\d{2}')->name('signoff.reopen');
        Route::patch('/{unit}/{date}/signoff', [EndorsementController::class, 'updateSignoff'])
            ->where('date', '\d{4}-\d{2}-\d{2}')->name('signoff.update');
    });

    // Day index + sheet + printable A4 (view).
    Route::middleware('cap:endorsement.view')->group(function () {
        Route::get('/{unit}', [EndorsementController::class, 'index'])->name('index');
        Route::get('/{unit}/{date}/print', [EndorsementController::class, 'print'])
            ->where('date', '\d{4}-\d{2}-\d{2}')->name('print');
        Route::get('/{unit}/{date}', [EndorsementController::class, 'show'])
            ->where('date', '\d{4}-\d{2}-\d{2}')->name('show');
    });
});

/*
 * Admin → Access Control. The catalog read and every write are admin-only
 * (`cap:access.manage`); writes are POST/PUT + CSRF-protected.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:access.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/access-control', [AccessControlController::class, 'index'])->name('access-control');
        // The page's single Save posts the WHOLE matrix; the per-role endpoint remains for
        // scripted/partial updates.
        Route::put('/access-control/roles', [AccessControlController::class, 'updateRoles'])->name('access-control.roles');
        Route::put('/access-control/role', [AccessControlController::class, 'updateRole'])->name('access-control.role');
        Route::put('/access-control/user', [AccessControlController::class, 'updateUser'])->name('access-control.user');
    });

/*
 * Admin → Users: approve/reject pending registrations, toggle activation,
 * change roles (the ONLY place position 0 can be granted), correct another
 * account's profile. Account DELETION is deliberately absent — deactivate instead.
 *
 * TWO TIERS: the shared endpoints (index/approve/reject/active) carry `auth` only and
 * are gated IN-CONTROLLER, because `users.manage_residents` (Chief Resident) may use
 * them on RESIDENT accounts alone and the refusal is audited with the attempted target.
 * Role and profile changes remain full-manager (`cap:users.manage`) routes.
 */
Route::middleware('auth')
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users/pending/{pending}/approve', [UserManagementController::class, 'approve'])->name('users.approve');
        Route::delete('/users/pending/{pending}', [UserManagementController::class, 'reject'])->name('users.reject');
        // {user} deliberately stays on the DEFAULT (trashed-excluding) binding here — P1c Task 7's
        // audit of every soft-deletable route binding, unlike {person} in admin/structure below.
        // UserManagementController's own docblock: user deletion was WITHDRAWN as a capability
        // (2026-07-19) and the route removed with it, so nothing in this codebase ever sets
        // `users.deleted_at`; index() above never lists a trashed account either, so there is no
        // legitimate path onto one through this screen at all. Reaching one anyway here would let
        // an activate/role/profile write bypass the audited deactivate-never-delete path the
        // ruling exists to enforce. Same reasoning covers users.position/users.profile below.
        Route::patch('/users/{user}/active', [UserManagementController::class, 'setActive'])->name('users.active');

        // Invitations are the only way an account is created. Same two-tier rule, applied
        // in-controller via ManagerScope: a Chief Resident may invite Residents alone.
        Route::post('/invitations', [\App\Http\Controllers\Admin\InvitationController::class, 'store'])
            ->name('invitations.store');
        Route::delete('/invitations/{invitation}', [\App\Http\Controllers\Admin\InvitationController::class, 'revoke'])
            ->name('invitations.revoke');
    });

Route::middleware(['auth', 'throttle:clinical', 'cap:users.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::patch('/users/{user}/position', [UserManagementController::class, 'setPosition'])->name('users.position');
        Route::patch('/users/{user}/profile', [UserManagementController::class, 'updateProfile'])->name('users.profile');
    });

/*
 * Admin → Settings: the runtime-configurable settings (SMTP, push, reminder times).
 * Secrets are write-only; every change is audited by key name, never by value.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:settings.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        // Tighter than `throttle:clinical` on purpose: this causes an outbound message, so
        // left wide open it is a small relay. Six a minute is plenty for testing a config.
        Route::post('/settings/test-email', [SettingsController::class, 'sendTestEmail'])
            ->middleware('throttle:6,1')->name('settings.test-email');
    });

/*
 * Admin → Structure: the department's SHAPE — units, training levels, the calendar, rota
 * periods and holidays (Munawib UN-01…05, LV-01, ST-02, ST-06). One capability covers all of
 * them: they are edited by the same person in the same sitting, and they are a different kind
 * of thing from `settings.manage`'s infrastructure.
 *
 * `/admin/structure/*` is deliberately NOT under `/endorsement`, so Unit::RESERVED_CODES —
 * which ReservedUnitCodesTest derives from the literal segments under /endorsement alone — is
 * unaffected by anything added here.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:structure.manage'])
    ->prefix('admin/structure')
    ->name('admin.structure.')
    ->group(function () {
        Route::get('/units', [UnitController::class, 'index'])->name('units');
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        // Declared BEFORE {unit} so `merge` never binds as a unit id — the same discipline the
        // endorsement routes use for `today`/`compliance`/`rows`.
        Route::get('/units/merge', [UnitMergeController::class, 'index'])->name('units.merge');
        Route::post('/units/merge', [UnitMergeController::class, 'store'])->name('units.merge.store');
        Route::patch('/units/{unit}', [UnitController::class, 'update'])->name('units.update');
        Route::patch('/units/{unit}/active', [UnitController::class, 'setActive'])->name('units.active');

        // Munawib LV-01. No DELETE — person_levels.level_id is restrictOnDelete, and
        // LevelController deliberately exposes no destroy() to refuse (see its own docblock).
        Route::get('/levels', [LevelController::class, 'index'])->name('levels');
        Route::post('/levels', [LevelController::class, 'store'])->name('levels.store');
        Route::patch('/levels/{level}', [LevelController::class, 'update'])->name('levels.update');
        Route::patch('/levels/{level}/active', [LevelController::class, 'setActive'])->name('levels.active');

        // Munawib ST-02. Declared BEFORE {unit}/{level}? Not applicable here — 'calendar' is
        // not a route parameter on either sibling group, so ordering is not load-bearing, but
        // the GET/PUT pair mirrors admin/settings' own shape.
        Route::get('/calendar', [CalendarSettingsController::class, 'index'])->name('calendar');
        Route::put('/calendar', [CalendarSettingsController::class, 'update'])->name('calendar.update');

        // Munawib MR-01. {academicYear} is regex-pinned — periods.academic_year is free text
        // (finding 15's own docblock) but the URI segment stays narrow on purpose.
        Route::get('/periods', [PeriodController::class, 'index'])->name('periods');
        Route::post('/periods', [PeriodController::class, 'store'])->name('periods.store');
        Route::delete('/periods/{academicYear}', [PeriodController::class, 'destroy'])
            ->where('academicYear', '[A-Za-z0-9\- ]{1,20}')->name('periods.destroy');

        // Munawib §30. No destroy() — a holiday observed last year is history; setActive() is
        // the only "this rule is done" action, mirroring Unit/Level's own precedent.
        Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays');
        Route::post('/holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::patch('/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
        Route::patch('/holidays/{holiday}/active', [HolidayController::class, 'setActive'])->name('holidays.active');
    });

/*
 * Admin → People: the departmental ROSTER (Munawib PE-01…03, LV-02…04, ST-04). Its own
 * capability, `people.manage`, deliberately separate from `users.manage` (the ACCOUNT console)
 * and from `structure.manage` (the department's shape) — see the P1c plan's Decision A.
 *
 * Nothing in this group creates an account. The invitation flow under `admin/invitations`
 * remains the only path from a roster entry to a credential.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:people.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/people', [PersonController::class, 'index'])->name('people');
        // Declared BEFORE any /people/{person} route so `visibility` never binds as a person id
        // — the same discipline routes/web.php already applies to `units/merge`.
        Route::patch('/people/visibility', [PersonController::class, 'updateVisibility'])->name('people.visibility');
        // LV-04. ->withTrashed() so a retired person's history stays reachable — index() already
        // lists retired people (UN-04's reasoning: an administrator who cannot SEE a retired
        // person cannot bring them back), and this route must not 404 the moment one is.
        Route::get('/people/{person}/history', [PersonController::class, 'history'])
            ->name('people.history')->withTrashed();
        Route::post('/people', [PersonController::class, 'store'])->name('people.store');
        // LV-02's bulk operations (set level, set status, export). A distinct URI, not a
        // `{person}`-shaped one, so it never collides with the PATCH route below.
        Route::post('/people/bulk', [PersonController::class, 'bulk'])->name('people.bulk');
        // ->withTrashed() for the same reason as people.history above (P1c Task 7's audit): the
        // roster's own Edit button is offered on every row index() lists, retired or not
        // (People.vue never conditions it on `retired`), so PATCHing a retired person's record
        // must not 404 either. This does not un-delete the row — restoring is the invitation /
        // re-approval flow's job (Person::matchByEmail() clears deleted_at there) — it only lets
        // an admin correct a retired person's fields the same way they can already view them.
        Route::patch('/people/{person}', [PersonController::class, 'update'])->name('people.update')->withTrashed();
        // No destroy — people are deactivated, never deleted (owner ruling). PersonController
        // exposes no delete action at all rather than a route that refuses, matching
        // LevelController's own precedent: DELETE against this URI is a plain 405.

        // LV-03's annual promotion (P1b Owner Decision A / P1c Decision D). The operator picks
        // BOTH ends explicitly; nothing here computes a target.
        Route::get('/promotion', [PromotionController::class, 'index'])->name('promotion');
        Route::post('/promotion/preview', [PromotionController::class, 'preview'])->name('promotion.preview');
        Route::post('/promotion/commit', [PromotionController::class, 'commit'])->name('promotion.commit');

        // ST-04's roster import (P1c Decision E). CSV/TSV only; the dry-run PREVIEW is the
        // deliverable — it writes nothing (see RosterImportController's own docblock) and is the
        // only thing standing between a malformed file and a corrupted roster.
        Route::get('/roster-import', [RosterImportController::class, 'index'])->name('roster-import');
        Route::post('/roster-import/preview', [RosterImportController::class, 'preview'])->name('roster-import.preview');
        Route::post('/roster-import/commit', [RosterImportController::class, 'commit'])->name('roster-import.commit');
    });

/*
 * Admin → Master Rota (Munawib MR-02/MR-03). `cap:rota.manage` — editing the rota is a
 * scheduling act, not a roster one. The READ view MR-05 requires is a separate route behind
 * `cap:rota.view` and is built in P1d-2; it deliberately does not live under `/admin`, because a
 * resident reading the rota is not doing administration.
 *
 * Deliberately NOT under `/endorsement`, so Unit::RESERVED_CODES is untouched
 * (ReservedUnitCodesTest asserts that list against the router bidirectionally).
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:rota.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/rota', [MasterRotaController::class, 'index'])->name('rota');

        // Per-cell save (Task 8), splits (Task 9) and vacations (Task 10) — declared together so
        // the URL space is settled in one commit; `RotaAssignment`/`VacationBooking` (Decision F)
        // remain the only writers of the tables these delegate to.
        Route::patch('/rota/cell', [MasterRotaController::class, 'setCell'])->name('rota.cell');
        Route::post('/rota/cell/split', [MasterRotaController::class, 'splitCell'])->name('rota.cell.split');
        Route::delete('/rota/cell', [MasterRotaController::class, 'clearCell'])->name('rota.cell.clear');
        Route::post('/rota/vacations', [MasterRotaController::class, 'bookVacation'])->name('rota.vacations.store');
        // {vacation} takes the DEFAULT binding — Vacation has no SoftDeletes (Decision E), so
        // there is no trashed row for the binding to exclude and no ->withTrashed() to add
        // (P1c Task 7's follow-up discipline: state this explicitly rather than leave a reader
        // to work it out).
        Route::delete('/rota/vacations/{vacation}', [MasterRotaController::class, 'cancelVacation'])->name('rota.vacations.destroy');

        // MR-06's bulk moves (P1d-2 Task 8). TWO routes, preview and confirm, because the preview
        // is the deliverable and must be incapable of writing — one route with an `apply=true` flag
        // would put the destructive path one boolean away from the safe one. Both POST + CSRF and
        // both inside this `cap:rota.manage` group: a fill behind `rota.view` would fail
        // `RotaAccessTest::test_every_route_behind_cap_rota_view_is_a_get`, which is what that
        // assertion is for.
        Route::post('/rota/fill/preview', [MasterRotaController::class, 'fillPreview'])->name('rota.fill.preview');
        Route::post('/rota/fill', [MasterRotaController::class, 'fill'])->name('rota.fill');

        // MR-06's export (P1d-2 Task 10, Decision G). TWO routes, not one route with a `?file=`
        // parameter and not a zip: each URL is independently bookmarkable and independently
        // audited, a zip would add a packaging path and an `ext-zip` question for no benefit, and
        // the screen can simply offer two buttons. Both GET — they write nothing; the audit row
        // records a disclosure, not a change.
        //
        // Behind `cap:rota.manage` rather than `cap:rota.view`: a whole-year extraction is an
        // administrative act and the input to the importer, and putting it in the read group would
        // hand every member of the department a one-click copy of the whole year.
        Route::get('/rota/export/assignments', [MasterRotaController::class, 'exportAssignments'])
            ->name('rota.export.assignments');
        Route::get('/rota/export/vacations', [MasterRotaController::class, 'exportVacations'])
            ->name('rota.export.vacations');

        // MR-06's import (P1d-2 Task 12, Decision H). The same preview/commit pair as the fill and
        // for the same reason: the preview is the deliverable and must be incapable of writing, so
        // it is a separate route rather than an `apply=true` flag one boolean away from the
        // destructive path. Behind `cap:rota.manage` like the export it reads back — a whole-year
        // overwrite is at least as administrative as a whole-year extraction.
        //
        // ONE screen for BOTH files; `kind` selects which, as a validated enum on the request and
        // never sniffed from the headers (the two files share four column names).
        Route::get('/rota/import', [RotaImportController::class, 'index'])->name('rota.import');
        Route::post('/rota/import/preview', [RotaImportController::class, 'preview'])->name('rota.import.preview');
        Route::post('/rota/import/commit', [RotaImportController::class, 'commit'])->name('rota.import.commit');
    });

/*
 * The master rota as a resident READS it (Munawib MR-05). `cap:rota.view` — seeded to every
 * authenticated position (P1d-1 owner decision 2), so this screen is read by the whole department,
 * which is exactly why `RotaGrid` projects contact-free for every viewer (P1d-2 Decision C).
 *
 * NOT under `/admin`, and its own controller rather than a method on `MasterRotaController`
 * (Decision A): that class sits wholly inside the `cap:rota.manage` group above, and a
 * `cap:rota.view` method on it would put one class behind two capabilities. A resident reading the
 * rota is not doing administration.
 *
 * ONE ROUTE, AND IT IS A GET — there is NO PUBLISH GATE (owner decision 1, 2026-08-10): `/rota`
 * always shows the current rota, so there is nothing here to POST. Do not add a write endpoint to
 * this group; `RotaAccessTest::test_every_route_behind_cap_rota_view_is_a_get` asserts the
 * GET-only property over the ROUTER, which is what makes it hold for routes nobody has written
 * yet.
 *
 * Deliberately NOT under `/endorsement`, so Unit::RESERVED_CODES is untouched. Do NOT add `ROTA`
 * to that list — ReservedUnitCodesTest derives it from the literal segments under that prefix
 * alone, bidirectionally, so an unnecessary entry fails the build just as a missing one does.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:rota.view'])
    ->group(function () {
        Route::get('/rota', [RotaController::class, 'index'])->name('rota');
    });

/*
 * First-login setup. `auth` only, no `cap:` — the capability catalogue is not the question
 * here; every account, whatever its role, answers these two before reaching a ward. It is
 * also the destination RequireSetup redirects to, so gating it on anything the new account
 * might lack would be a trap.
 */
Route::middleware('auth')->group(function () {
    Route::get('/setup', [\App\Http\Controllers\SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup', [\App\Http\Controllers\SetupController::class, 'complete'])->name('setup.complete');
});

/*
 * Own profile. Every seeded role holds `cap:profile.manage`; the update binds
 * to the SESSION identity only (IDOR-safe).
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:profile.manage'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/reminders', [ProfileController::class, 'updateReminders'])->name('profile.reminders');

    // Change your own password (current password required), choose the second factor,
    // and manage the handwritten signature that prints on sheets you sign.
    // A page of its own rather than a panel on the profile: changing a password is a
    // deliberate, single-purpose act, and it sat below two unrelated forms where an
    // accidental submit of the wrong one was easy. NOT to be confused with the FORCED
    // change at password.change, which runs UNAUTHENTICATED for an expired password.
    Route::get('/profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:6,1')->name('profile.password');
    Route::put('/profile/two-factor-method', [ProfileController::class, 'updateTwoFactorMethod'])->name('profile.two-factor-method');
    Route::post('/profile/signature', [ProfileController::class, 'updateSignature'])->name('profile.signature');
    Route::delete('/profile/signature', [ProfileController::class, 'deleteSignature'])->name('profile.signature.delete');
});

/*
 * Staff signatures. Never public files: `/signatures/{user}` is your own (or, with the
 * endorsement read gate, a colleague's current one) and `/signatures/file/{hash}` serves
 * the immutable image a signed sheet was signed with.
 */
Route::middleware('auth')->group(function () {
    // `no_history` on each: these are fetched by an <img>, never navigated to. Without it the
    // browser's image request became the session's "previous page", and every later back()
    // redirected to a PNG — which Inertia rendered as a full-screen wall of raw bytes.
    // See App\Http\Middleware\StartSession.
    Route::get('/signatures/file/{hash}', [SignatureController::class, 'file'])
        ->defaults('no_history', true)->name('signatures.file');
    Route::get('/signatures/me', [SignatureController::class, 'mine'])
        ->defaults('no_history', true)->name('signatures.mine');
    // {user} stays on the DEFAULT (trashed-excluding) binding too (P1c Task 7's audit):
    // SignatureController::show() refuses anyone but the viewer's own account, and an
    // authenticated session can only ever resolve to a non-trashed user in the first place —
    // there is no legitimate way for {user} to be a retired account here.
    Route::get('/signatures/{user}', [SignatureController::class, 'show'])
        ->defaults('no_history', true)->name('signatures.show');
});

/*
 * This device's web-push subscription (spec §10.2). Session-bound; endpoints and keys
 * only, no clinical data.
 */
Route::middleware('auth')->group(function () {
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('cap:profile.manage')->name('push.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('cap:profile.manage')->name('push.destroy');
    // Prove push works now, rather than at the next 07:30. Throttled: it reaches an
    // external push service and puts a notification on someone's phone.
    Route::post('/push/test', [PushSubscriptionController::class, 'test'])
        ->middleware(['cap:profile.manage', 'throttle:6,1'])->name('push.test');
});

require __DIR__.'/auth.php';
