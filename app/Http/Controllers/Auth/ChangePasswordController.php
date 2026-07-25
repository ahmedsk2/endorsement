<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forced password change reached from the login flow when a password has expired (> 3 months).
 * The user is NOT logged in during this flow — identity is carried as a user id in the session
 * (set by AuthenticatedSessionController). The user re-proves their (still-valid) current
 * password, sets a new one, and is sent back to sign in.
 */
class ChangePasswordController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->expiredUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/ChangePassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->expiredUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', \App\Support\PasswordPolicy::rule(), 'different:current_password'],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'pass_exp_date' => now()->toDateString(),
        ])->save();

        $request->session()->forget(AuthenticatedSessionController::PASSWORD_EXPIRED_SESSION_KEY);

        return redirect()->route('login')->with('status', 'Password changed — please sign in.');
    }

    private function expiredUser(Request $request): ?User
    {
        $id = $request->session()->get(AuthenticatedSessionController::PASSWORD_EXPIRED_SESSION_KEY);

        return $id ? User::find($id) : null;
    }
}
