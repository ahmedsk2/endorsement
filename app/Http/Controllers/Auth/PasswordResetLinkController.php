<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password" — mails a reset link via Laravel's password broker. The broker resolves
 * the account by the `member_email` column. The response is a single generic status in every
 * case so the endpoint can't be used to enumerate accounts.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Key the credential by the real column (`member_email`); ignore the broker's status so
        // an unknown address returns the same generic message as a known one.
        Password::sendResetLink(['member_email' => $request->input('email')]);

        return back()->with('status', 'If that account exists, a password reset link has been sent.');
    }
}
