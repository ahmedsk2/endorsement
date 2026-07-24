<?php

use App\Http\Controllers\Admin\AccessControlController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// The root lands on the four-unit endorsement chooser (built in Phases 3-4).
Route::get('/', fn () => redirect('/endorsement'));

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
});

require __DIR__.'/auth.php';
