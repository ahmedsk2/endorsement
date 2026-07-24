<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F1 — own-profile editing (re-platform of legacy profile.php). `auth` + `cap:profile.manage`
 * (every seeded role). The update binds to the SESSION identity only — any client-supplied id
 * is ignored (IDOR-safe, mirroring the legacy page; admin editing of OTHER users lives in
 * Admin\UserManagementController). member_name / member_email are uniqueness-checked against
 * `users` (excluding self — soft-deleted rows still occupy the unique indexes, so they count)
 * AND the `pending_registrations` queue, exactly like public registration.
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
            // Spec §10.2 — the per-unit reminder opt-in + the public half of the VAPID
            // pair (public by definition; the private key never leaves the server env).
            'reminders' => [
                'units' => \App\Models\Unit::whereIn('code', \App\Support\UnitProfile::codes())
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

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'member_name' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'member_name')->ignore($user->getKey()),
                Rule::unique('pending_registrations', 'member_name'),
            ],
            'member_email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'member_email')->ignore($user->getKey()),
                Rule::unique('pending_registrations', 'member_email'),
            ],
        ]);

        // Explicit field list — nothing else from the request can reach the model.
        $user->update([
            'full_name' => $data['full_name'],
            'member_name' => $data['member_name'],
            'member_email' => $data['member_email'],
        ]);

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
}
