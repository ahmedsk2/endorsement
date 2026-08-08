<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F1 — own-profile editing (re-platform of legacy profile.php). `auth` + `cap:profile.manage`
 * (every seeded role). The update binds to the SESSION identity only — any client-supplied id
 * is ignored (IDOR-safe, mirroring the legacy page; admin editing of OTHER users lives in
 * Admin\UserManagementController). `member_name` is uniqueness-checked against `users`
 * (excluding self — soft-deleted rows still occupy the unique indexes, so they count) AND the
 * `pending_registrations` queue. `member_email` is checked against `people.email` instead — the
 * single authoritative address since P0c/D9 (owner decision 2026-08-08) — plus the pending
 * queue.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'full_name' => $user->full_name,
                'member_name' => $user->member_name,
                'member_email' => $user->member_email,
            ],
            // The account-assurance state the profile page manages, and the setup
            // checklist on the chooser nags about.
            'security' => [
                'email' => $user->member_email,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_method' => $user->two_factor_method,
                'two_factor_active' => $user->activeTwoFactorMethod(),
                'totp_confirmed' => $user->hasTwoFactorEnabled(),
                'has_signature' => $user->hasSignature(),
                'signature_updated_at' => $user->signature_updated_at?->format('Y-m-d H:i'),
            ],
            // Spec §10.2 — the per-unit reminder opt-in + the public half of the VAPID
            // pair (public by definition; the private key never leaves the server env).
            'reminders' => [
                'units' => \App\Models\Unit::query()->active()->ordered()
                    ->get(['id', 'code', 'name'])
                    ->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name]),
                'selected' => $user->reminderUnits()->pluck('units.id'),
                'vapid_public_key' => (string) config('endorsement.vapid.public_key'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // SESSION identity only — never a submitted id (IDOR-safe).
        $user = $request->user();

        // Normalize BEFORE validating: `Rule::unique('people', 'email')` below runs a raw `WHERE
        // email = ?` against the submitted value, but every stored address is normalized
        // (Person::normalizeEmail — lowercased, trimmed) on write. Production MySQL's
        // utf8mb4_unicode_ci collation happens to catch a differently-cased duplicate anyway, but
        // that is a collation accident, not something this check should depend on — a
        // case-sensitive collation would let a duplicate through validation and then hit
        // `people.email`'s unique index as a raw 500. Normalizing the input first makes the
        // comparison correct regardless of collation.
        $request->merge(['member_email' => Person::normalizeEmail($request->input('member_email'))]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'member_name' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'member_name')->ignore($user->getKey()),
                Rule::unique('pending_registrations', 'member_name'),
            ],
            'member_email' => [
                'required', 'email', 'max:255',
                Rule::unique('pending_registrations', 'member_email'),
                Rule::unique('people', 'email')->ignore($user->person_id),
            ],
        ]);

        // One email column now, on `people` (owner decision 2026-08-08, overriding the plan's
        // original dual-column draft). `member_email` is a read-through accessor on User, not a
        // column — writing it here would silently do nothing.
        DB::transaction(function () use ($user, $data): void {
            $user->update([
                'member_name' => $data['member_name'],
            ]);

            $user->person?->update([
                'full_name' => $data['full_name'],
                'email' => Person::normalizeEmail($data['member_email']),
            ]);
        });

        AuditLog::record(
            'profile_update',
            'user='.$user->getKey(),
            $user->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Profile updated.');
    }

    /**
     * Spec 10.2 - the per-unit reminder opt-in. Replaces the whole set each submit so
     * unchecking works; binds to the SESSION identity only.
     */
    public function updateReminders(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_ids' => ['sometimes', 'array'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ]);

        $request->user()->reminderUnits()->sync($data['unit_ids'] ?? []);

        return back()->with('status', 'Reminder preferences saved.');
    }

    /**
     * Change your own password while signed in. The CURRENT password is required — a
     * hijacked session must not be able to lock the real owner out — and the new one must
     * meet the same four requirements the registration page checklists.
     */
    /**
     * The change-password page.
     *
     * Deliberately separate from the FORCED change at `password.change`
     * (App\Http\Controllers\Auth\ChangePasswordController), which runs before the user is
     * authenticated, because their password has expired. These two look alike and must not
     * be merged: this one requires a live session and the current password; that one
     * cannot, because its user has not been logged in yet.
     */
    public function editPassword(): \Inertia\Response
    {
        return \Inertia\Inertia::render('Profile/Password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required', 'confirmed',
                \App\Support\PasswordPolicy::rule(),
            ],
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->getAuthPassword())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        if (\Illuminate\Support\Facades\Hash::check($data['password'], $user->getAuthPassword())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password' => 'Choose a password you have not used here before.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],           // hashed by the model cast
            'pass_exp_date' => today(),                // restart the 3-month expiry clock
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();

        // Every OTHER session for this account dies with the old password.
        \Illuminate\Support\Facades\DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $request->session()->regenerate();

        // Changing a password is what someone does when they think something is wrong, so it
        // must also end "don't ask for a code on this device" everywhere. Otherwise the one
        // action a worried user knows to take would leave the second factor still skipped on
        // a machine they no longer trust.
        \App\Support\TrustedDevice::revokeAll($user);

        AuditLog::record('password_changed', 'user='.$user->getKey(), $user->getKey(), $request->ip());

        return back()->with('status', 'Password changed. Other devices have been signed out, and each will be asked for a code again.');
    }

    /**
     * Choose the second factor: an authenticator app (TOTP — enrolled on its own page) or
     * a code by email. 'email' is only accepted once the address is confirmed, otherwise
     * the factor would be delivered to an address nobody has proved they own.
     */
    public function updateTwoFactorMethod(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'method' => ['present', 'nullable', 'in:totp,email'],
        ]);

        $method = $data['method'] ?? null;

        if ($method === 'email' && ! $user->hasVerifiedEmail()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'method' => 'Confirm your email address before using it for sign-in codes.',
            ]);
        }

        if ($method === 'totp' && ! $user->hasTwoFactorEnabled()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'method' => 'Set up your authenticator app first, then select this option.',
            ]);
        }

        $user->forceFill(['two_factor_method' => $method])->save();

        // Turning two-step sign-in OFF must also forget the devices that were skipping it —
        // otherwise "I switched it off" leaves a week of skipped codes outstanding, and the
        // sibling route (DELETE /user/two-factor) already revokes, so the same user-visible
        // act had two different outcomes depending on which control they reached for.
        if ($method === null) {
            \App\Support\TrustedDevice::revokeAll($user);
        }

        AuditLog::record(
            'two_factor_method_set',
            'user='.$user->getKey().';method='.($method ?? 'none'),
            $user->getKey(),
            $request->ip(),
        );

        return back()->with('status', $method === null
            ? 'Two-step sign-in turned off.'
            : 'Two-step sign-in updated.');
    }

    /**
     * Store the user's handwritten signature — either an uploaded image or a canvas
     * drawing (data URL). Files are private and content-addressed, so this never
     * overwrites the signature an already-signed handover points at.
     */
    public function updateSignature(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'signature_file' => ['sometimes', 'nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'signature_data' => ['sometimes', 'nullable', 'string', 'max:4000000'],
        ]);

        $path = null;

        if ($request->hasFile('signature_file')) {
            $path = \App\Support\SignatureStore::putUpload($request->file('signature_file'));
        } elseif (filled($request->input('signature_data'))) {
            $path = \App\Support\SignatureStore::putDataUrl((string) $request->input('signature_data'));
        }

        if ($path === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => 'Draw a signature or choose an image file.',
            ]);
        }

        $user->forceFill([
            'signature_path' => $path,
            'signature_updated_at' => now(),
        ])->save();

        AuditLog::record('signature_updated', 'user='.$user->getKey(), $user->getKey(), $request->ip());

        return back()->with('status', 'Signature saved.');
    }

    /** Remove the signature from the ACCOUNT. Already-signed sheets keep theirs. */
    public function deleteSignature(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['signature_path' => null, 'signature_updated_at' => null])->save();

        AuditLog::record('signature_removed', 'user='.$user->getKey(), $user->getKey(), $request->ip());

        return back()->with('status', 'Signature removed. Sheets you already signed keep the signature they were signed with.');
    }
}
