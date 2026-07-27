<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Complete a password reset from a tokened link (Laravel's password broker). The account is
 * resolved by `member_email`; a successful reset re-stamps `pass_exp_date`, rotates the
 * remember token, and kills any live sessions for the account.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => \App\Support\PasswordPolicy::rules(),
        ]);

        $status = Password::reset(
            [
                'member_email' => $request->input('email'),
                'password' => $request->input('password'),
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $request->input('token'),
            ],
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->input('password')),
                    'pass_exp_date' => now()->toDateString(),
                    'remember_token' => Str::random(60),
                ])->save();

                // A reset is a recovery action — a stolen live session must not survive it.
                DB::table('sessions')->where('user_id', $user->id)->delete();

                // Nor may a device that was skipping the second factor. This is the path a
                // user takes when they cannot sign in at all, which is exactly the case
                // where the authenticator is on the lost device — so a reset that restored
                // only the password would restore only half the protection, and leave the
                // TOTP skip live on the very machine in question for the rest of its window.
                //
                // It is also the promise the checkbox made: "Changing your password ends
                // this everywhere." From the clinician's side a reset IS a password change.
                \App\Support\TrustedDevice::revokeAll($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return redirect()->route('login')->with('status', 'Password reset — please sign in.');
    }
}
