<?php

use App\Http\Controllers\Admin\AccessControlController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\EndorsementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
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
Route::middleware('auth')->prefix('endorsement')->name('endorsement.')->group(function () {
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
        Route::patch('/rows/{handover}', [EndorsementController::class, 'updateRow'])->name('rows.update');
        Route::delete('/rows/{handover}', [EndorsementController::class, 'deleteRow'])->name('rows.delete');
        Route::post('/{unit}/new-day', [EndorsementController::class, 'newDay'])->name('new-day');
        Route::post('/{unit}/{date}/rows', [EndorsementController::class, 'storeRow'])
            ->where('date', '\d{4}-\d{2}-\d{2}')->name('rows.store');

        // The per-day shift attestation (legacy `validate-endorsement.php`). The reopen
        // sub-route is declared first so `reopen` never binds as part of the signoff path.
        // Reopen additionally requires `endorsement.reopen`, checked IN-CONTROLLER so the
        // 403 can name the actual active holders.
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
Route::middleware(['auth', 'cap:access.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/access-control', [AccessControlController::class, 'index'])->name('access-control');
        Route::put('/access-control/role', [AccessControlController::class, 'updateRole'])->name('access-control.role');
        Route::put('/access-control/user', [AccessControlController::class, 'updateUser'])->name('access-control.user');
    });

/*
 * Admin → Users: approve/reject pending registrations, toggle activation,
 * change roles (the ONLY place position 0 can be granted), correct another
 * account's profile. Account DELETION is deliberately absent — deactivate instead.
 */
Route::middleware(['auth', 'cap:users.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users/pending/{pending}/approve', [UserManagementController::class, 'approve'])->name('users.approve');
        Route::delete('/users/pending/{pending}', [UserManagementController::class, 'reject'])->name('users.reject');
        Route::patch('/users/{user}/active', [UserManagementController::class, 'setActive'])->name('users.active');
        Route::patch('/users/{user}/position', [UserManagementController::class, 'setPosition'])->name('users.position');
        Route::patch('/users/{user}/profile', [UserManagementController::class, 'updateProfile'])->name('users.profile');
    });

/*
 * Own profile. Every seeded role holds `cap:profile.manage`; the update binds
 * to the SESSION identity only (IDOR-safe).
 */
Route::middleware(['auth', 'cap:profile.manage'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/reminders', [ProfileController::class, 'updateReminders'])->name('profile.reminders');
});

/*
 * This device's web-push subscription (spec §10.2). Session-bound; endpoints and keys
 * only, no clinical data.
 */
Route::middleware('auth')->group(function () {
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
});

require __DIR__.'/auth.php';
