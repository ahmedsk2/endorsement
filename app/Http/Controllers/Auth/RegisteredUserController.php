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
            'positions' => [
                1 => 'Nurse',
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
            'position' => ['required', 'integer', Rule::in([1, 2, 3, 4])],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        PendingRegistration::create([
            'full_name' => $data['full_name'],
            'member_name' => $data['member_name'],
            'member_email' => $data['member_email'],
            'position' => $data['position'],
            'password' => $data['password'], // hashed by the model cast
            'requested_at' => now(),
        ]);

        return redirect()->route('login')->with(
            'status',
            'Registration received — an administrator must activate your account before you can sign in.'
        );
    }
}
