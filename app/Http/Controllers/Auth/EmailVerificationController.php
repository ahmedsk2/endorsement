<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm your email address" for BOTH a pending registration and an existing account.
 *
 * The link is a Laravel SIGNED URL — an HMAC over the route, its parameters and an expiry,
 * keyed by APP_KEY. Nothing is stored, a tampered link fails the signature check, and the
 * embedded address hash means a link stops working the moment the address is changed.
 *
 * Verification is deliberately NOT a login: confirming a registration only marks the queue
 * row, and an administrator (or Chief Resident, for residents) still has to approve it.
 */
class EmailVerificationController extends Controller
{
    public const LINK_HOURS = 48;

    /** The signed link mailed to a pending registration. */
    public static function registrationUrl(PendingRegistration $pending): string
    {
        return URL::temporarySignedRoute('registration.verify', now()->addHours(self::LINK_HOURS), [
            'pending' => $pending->getKey(),
            'hash' => sha1((string) $pending->member_email),
        ]);
    }

    /** The signed link mailed to an existing account. */
    public static function accountUrl(User $user): string
    {
        return URL::temporarySignedRoute('profile.email.verify', now()->addHours(self::LINK_HOURS), [
            'user' => $user->getKey(),
            'hash' => sha1((string) $user->member_email),
        ]);
    }

    /** Send (or re-send) the registration confirmation mail. */
    public static function sendRegistrationLink(PendingRegistration $pending): void
    {
        if ($pending->member_email === null || $pending->member_email === '') {
            return;
        }

        Notification::route('mail', $pending->member_email)->notify(
            new VerifyEmailAddress(self::registrationUrl($pending), $pending->full_name, true),
        );
    }

    /** A registrant opens the link: mark the queue row verified. */
    public function verifyRegistration(Request $request, PendingRegistration $pending, string $hash): RedirectResponse
    {
        // The address must still be the one the link was issued for.
        if (! hash_equals(sha1((string) $pending->member_email), $hash)) {
            abort(403, 'This confirmation link is no longer valid for that email address.');
        }

        if ($pending->email_verified_at === null) {
            $pending->forceFill(['email_verified_at' => now()])->save();

            // Ids only — the address itself is personal data and stays out of the trail.
            AuditLog::record('registration_email_verified', 'pending='.$pending->getKey(), null, $request->ip());
        }

        return redirect()->route('login')->with(
            'status',
            'Email confirmed. An administrator will activate your account — you will be able to sign in once they do.',
        );
    }

    /** An existing user asks for a confirmation link for their own address. */
    public function sendAccountLink(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->member_email === null || $user->member_email === '') {
            return back()->with('error', 'Add an email address to your profile first.');
        }

        if ($user->hasVerifiedEmail()) {
            return back()->with('status', 'Your email address is already confirmed.');
        }

        Notification::route('mail', $user->member_email)->notify(
            new VerifyEmailAddress(self::accountUrl($user), $user->full_name, false),
        );

        return back()->with('status', 'Confirmation email sent — check your inbox.');
    }

    /** The user opens the link for their own account. */
    public function verifyAccount(Request $request, User $user, string $hash): RedirectResponse
    {
        if (! hash_equals(sha1((string) $user->member_email), $hash)) {
            abort(403, 'This confirmation link is no longer valid for that email address.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();

            AuditLog::record('account_email_verified', 'user='.$user->getKey(), $user->getKey(), $request->ip());
        }

        return redirect()->route('profile.edit')->with('status', 'Email address confirmed.');
    }
}
