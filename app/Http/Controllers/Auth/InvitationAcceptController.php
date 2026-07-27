<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Redeem an invitation: the only route by which an account now comes into existence.
 *
 * What the invitee supplies is their name, their username and their password. What they do
 * NOT supply — and what therefore cannot be tampered with — is their email address and
 * their role: both travel with the invitation, decided by whoever issued it. There is no
 * request in this flow carrying a self-declared position.
 *
 * The account is created ACTIVE and email-verified, with no second approval step, and that
 * is not a weakening of the old flow. Under self-registration, approval was where a human
 * first decided this person should exist and what they should be; here that decision was
 * already made, by a named manager, before the link was sent. Redeeming a link delivered to
 * one fixed address is also exactly what the verification email proved, so requiring a
 * further confirmation would be asking the same question twice.
 */
class InvitationAcceptController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::redeemable($token);

        if ($invitation === null) {
            // One message for expired, revoked, already-used and never-existed. Telling a
            // stranger which of those it was turns this into an oracle for valid addresses.
            return redirect()->route('login')->with(
                'error',
                'That invitation link is no longer valid. Ask the person who invited you to send a new one.',
            );
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'member_email' => $invitation->member_email,
            'position_label' => \App\Http\Controllers\Admin\InvitationController::OFFERABLE[$invitation->position]
                ?? (string) $invitation->position,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::redeemable($token);

        if ($invitation === null) {
            return redirect()->route('login')->with(
                'error',
                'That invitation link is no longer valid. Ask the person who invited you to send a new one.',
            );
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'member_name' => [
                'required', 'string', 'max:255',
                // Soft-deleted accounts still hold the unique index, so they count.
                Rule::unique('users', 'member_name'),
                Rule::unique('pending_registrations', 'member_name'),
            ],
            'password' => PasswordPolicy::rules(),
        ]);

        $userId = DB::transaction(function () use ($data, $invitation): int {
            // Re-check inside the transaction with a lock. Two people opening the same link
            // at once would otherwise both pass the check above and create two accounts from
            // one invitation.
            $locked = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isOpen()) {
                throw new \RuntimeException('invitation-consumed');
            }

            $now = now();

            $userId = DB::table('users')->insertGetId([
                'institution_id' => $locked->institution_id,
                // FROM THE INVITATION, never from the request.
                'position' => $locked->position,
                'member_email' => $locked->member_email,
                'full_name' => $data['full_name'],
                'member_name' => $data['member_name'],
                'password' => Hash::make($data['password']),
                'active' => true,
                // Redeeming a link delivered to this address IS the proof of the address.
                'email_verified_at' => $now,
                // Arm the 90-day rotation, as the approval path does. Left null it never fires.
                'pass_exp_date' => $now->copy()->addDays(90)->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locked->forceFill([
                'accepted_at' => $now,
                'accepted_user_id' => $userId,
            ])->save();

            return $userId;
        });

        AuditLog::record(
            'invitation_accepted',
            'invitation='.$invitation->id.' member='.$userId.' position='.$invitation->position,
            // No session yet — the actor is recorded as the new account itself.
            $userId,
            $request->ip(),
        );

        return redirect()->route('login')->with(
            'status',
            'Your account is ready. Sign in with the username you just chose.',
        );
    }
}
