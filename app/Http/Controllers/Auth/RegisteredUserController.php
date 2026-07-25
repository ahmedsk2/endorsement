<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PendingRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public self-registration. Legacy parity: this NEVER creates an active `users` row and never
 * logs anyone in — it writes a `pending_registrations` row (hashed password) that an
 * administrator approves later (that approval flow is a separate task).
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            // 0 = Administrator is deliberately NOT offered to self-registration.
            // 1 = Nurse is RETIRED from this system. 5 = Chief Resident is never
            // registered — a chief registers as a Resident and is promoted by an
            // Administrator afterwards.
            'positions' => [
                2 => 'Charge Nurse',
                3 => 'Consultant',
                4 => 'Resident',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'member_name' => [
                'required', 'string', 'max:255',
                // Unique across BOTH the live users and the pending queue.
                Rule::unique('users', 'member_name'),
                Rule::unique('pending_registrations', 'member_name'),
            ],
            'member_email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'member_email'),
                Rule::unique('pending_registrations', 'member_email'),
            ],
            'position' => ['required', 'integer', Rule::in([2, 3, 4])],
            // ONE definition of a valid password for the whole system — the same rule the
            // registration page's live checklist mirrors (App\Support\PasswordPolicy).
            'password' => \App\Support\PasswordPolicy::rules(),
        ]);

        $pending = PendingRegistration::create([
            'full_name' => $data['full_name'],
            'member_name' => $data['member_name'],
            'member_email' => $data['member_email'],
            'position' => $data['position'],
            'password' => $data['password'], // hashed by the model cast
            'requested_at' => now(),
        ]);

        // Confirm the address BEFORE anybody activates the account: an administrator
        // should never switch on an inbox nobody has proved they own, and password
        // resets and (optionally) sign-in codes are delivered there.
        EmailVerificationController::sendRegistrationLink($pending);

        return redirect()->route('login')->with(
            'status',
            'Registration received — confirm your email address using the link we just sent, then an administrator will activate your account.'
        );
    }
}
