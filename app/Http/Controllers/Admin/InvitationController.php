<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Support\ManagerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Issue and revoke invitations — the only way an account is now created.
 *
 * The positions offered here are the ones self-registration used to offer, for the same
 * reasons: 0 (Administrator) is never granted this way, 1 (Nurse) is retired, and 5 (Chief
 * Resident) is a promotion an Administrator applies to an existing account rather than a
 * role anyone is created into. Widening this list would make an invitation a route to
 * privilege, which is exactly what it must not be.
 */
class InvitationController extends Controller
{
    public const OFFERABLE = [
        2 => 'Charge Nurse',
        3 => 'Consultant',
        4 => 'Resident',
    ];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_email' => [
                'required', 'email', 'max:255',
                // Not already an account, not already queued. Soft-deleted users still
                // occupy the unique index, so an invitation that could never be redeemed is
                // refused at the point of sending rather than at the point of trying.
                Rule::unique('users', 'member_email')->withoutTrashed(),
                Rule::unique('pending_registrations', 'member_email'),
            ],
            'position' => ['required', 'integer', Rule::in(array_keys(self::OFFERABLE))],
        ]);

        // The same two-tier rule that governs approving and rejecting accounts: a Chief
        // Resident may invite Residents, nobody else.
        ManagerScope::assertMayTarget($request, (int) $data['position']);

        $email = Str::lower(trim($data['member_email']));

        // One open invitation per address. Without this, "resend" quietly multiplies live
        // credentials for the same person and revoking one leaves the others working.
        //
        // Each superseded invitation is authorised INDIVIDUALLY and audited. A blanket
        // update was neither: a Chief Resident inviting an address that already held a
        // Consultant invitation would have cancelled it — an action they cannot perform
        // through the revoke route — and nothing would have recorded that it happened.
        $superseded = Invitation::query()
            ->where('member_email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->get();

        foreach ($superseded as $old) {
            ManagerScope::assertMayTarget($request, (int) $old->position);
        }

        foreach ($superseded as $old) {
            $old->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $request->user()?->getKey(),
            ])->save();

            AuditLog::record(
                'invitation_revoked',
                'invitation='.$old->id.' reason=superseded',
                $request->user()?->getKey(),
                $request->ip(),
            );
        }

        [$invitation, $token] = Invitation::issue($email, (int) $data['position'], $request->user());

        AuditLog::record(
            'invitation_issued',
            'invitation='.$invitation->id.' position='.$invitation->position,
            $request->user()?->getKey(),
            $request->ip(),
        );

        $link = route('invitation.show', ['token' => $token]);

        // Deliver it if mail is configured; otherwise the link below is the delivery. Never
        // fail the invitation because the mailer is down — the inviter still holds a working
        // link, and losing it silently would be worse than not sending.
        $mailed = false;

        if (config('mail.default') === 'smtp') {
            try {
                Mail::to($email)->send(new \App\Mail\InvitationMail($link, $invitation->expires_at));
                $mailed = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Invitation email could not be delivered: '.$e->getMessage());
            }
        }

        // The token is returned to the INVITER exactly once and is never stored in
        // plaintext, never audited, and never logged. Flashed rather than redirected into
        // the URL, because a bearer credential in a query string lands in history and logs.
        return back()->with('invitation_link', $link)->with(
            'status',
            $mailed
                ? 'Invitation sent. The link below works too, and it is shown only once.'
                : 'Invitation created. Copy the link below — it is shown only once.',
        );
    }

    public function revoke(Request $request, Invitation $invitation): RedirectResponse
    {
        ManagerScope::assertMayTarget($request, (int) $invitation->position);

        // Revoking a spent or already-revoked invitation is a no-op, not an error: the
        // outcome the caller wanted is already true.
        if ($invitation->isOpen()) {
            $invitation->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $request->user()?->getKey(),
            ])->save();

            AuditLog::record(
                'invitation_revoked',
                'invitation='.$invitation->id,
                $request->user()?->getKey(),
                $request->ip(),
            );
        }

        return back()->with('status', 'Invitation revoked.');
    }

    /**
     * The open invitations, for the Users screen. No token, hashed or otherwise, ever
     * reaches the page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function openInvitations(?User $viewer): array
    {
        return Invitation::query()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy:id,full_name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invitation $i) => [
                'id' => $i->id,
                'member_email' => $i->member_email,
                'position' => $i->position,
                'position_label' => self::OFFERABLE[$i->position] ?? (string) $i->position,
                'invited_by' => $i->invitedBy?->full_name,
                'expires_at' => $i->expires_at->format('Y-m-d H:i'),
            ])
            ->all();
    }

    /** Kept for the pending queue that predates invitations. */
    public static function pendingCount(): int
    {
        return PendingRegistration::count();
    }
}
