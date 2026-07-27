<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * B4 — opt-in TOTP second factor, managed on the user's own profile.
 *
 * Enrollment is a two-step, confirm-before-active flow:
 *   1. POST /user/two-factor         — generate a fresh secret + 8 recovery codes (stored
 *      encrypted, but NOT yet active: `two_factor_confirmed_at` stays null). The plaintext
 *      secret / otpauth URI / recovery codes are flashed for a ONE-TIME display.
 *   2. POST /user/two-factor/confirm — a valid TOTP code against the pending secret sets
 *      `two_factor_confirmed_at`, which is what the login flow challenges on.
 *   DELETE /user/two-factor          — disable: clears all three columns.
 */
class TwoFactorAuthenticationController extends Controller
{
    /** How many single-use recovery codes to mint on enrollment. */
    private const RECOVERY_CODE_COUNT = 8;

    /** TOTP drift tolerance (± time-steps) — kept small to limit the replay window. */
    private const VERIFY_WINDOW = 1;

    private function google2fa(): Google2FA
    {
        return new Google2FA;
    }

    /** The profile 2FA page. Enrollment data (if any) is read once from the flash. */
    public function show(Request $request): HttpResponse
    {
        $user = $request->user();

        // no-store: the one-time enrollment payload (plaintext secret + recovery codes) must
        // never be cached by the browser/intermediaries or retained in history.
        return Inertia::render('Profile/TwoFactor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            // Present only immediately after a `store()` — shown once, never re-served.
            'enrollment' => $request->session()->get('twoFactor'),
        ])->toResponse($request)->header('Cache-Control', 'no-store');
    }

    /** Begin enrollment: mint a secret + recovery codes; return them once for the QR + backup. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => 'Two-factor authentication is already enabled. Disable it first to re-enroll.',
            ]);
        }

        $secret = $this->google2fa()->generateSecretKey();
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => null,
        ])->save();

        $otpauthUri = $this->google2fa()->getQRCodeUrl(
            config('app.name', 'Paediatric Endorsement'),
            (string) ($user->member_email ?: $user->member_name),
            $secret,
        );

        // Flash the plaintext material for a single render (QR + manual key + backup codes).
        return redirect()->route('two-factor.show')->with('twoFactor', [
            'secret' => $secret,
            'otpauthUri' => $otpauthUri,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    /** Confirm enrollment by verifying a code against the pending secret. */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate(['code' => ['required', 'string']]);

        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages([
                'code' => 'Start by generating a two-factor secret first.',
            ]);
        }

        $valid = $this->google2fa()->verifyKey(
            $user->two_factor_secret,
            $this->normalizeCode($request->input('code')),
            self::VERIFY_WINDOW,
        );

        if ($valid === false) {
            throw ValidationException::withMessages([
                'code' => 'That code is incorrect — check your authenticator app and try again.',
            ]);
        }

        // CONFIRMING IS CHOOSING. The method used to be set on a different route, on the
        // profile page, and enrolment left it null — so the user had to come back and pick
        // "Authenticator app" a second time, and picking it BEFORE enrolling was rejected by
        // design. Two saves, the first of which could not succeed.
        //
        // It also left a state that contradicted itself: sign-in demanded a TOTP code
        // (AuthenticatedSessionController's fallback for an enrolled-but-unchosen account)
        // while EnforceTwoFactor read the null method and told the same user, on every page,
        // that they had no second factor.
        //
        // Nobody proves possession of a TOTP secret in order to then not use it.
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_method' => 'totp',
        ])->save();

        AuditLog::record('2fa_enable', 'member='.$user->id, $user->id, $request->ip());

        return redirect()->route('two-factor.show')
            ->with('status', 'Two-factor authentication is now enabled. You will be asked for a code at your next sign-in.');
    }

    /** Disable: clear the secret, recovery codes, and confirmation timestamp. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $wasEnabled = $user->hasTwoFactorEnabled();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Stand the method down too, but ONLY if it was the authenticator. Leaving
            // 'totp' behind with no secret is the same contradiction as before, mirrored:
            // the profile insists the authenticator is selected while every page tells a
            // privileged user they have no second factor. Someone relying on email codes
            // must not have them silently switched off by this.
            'two_factor_method' => $user->two_factor_method === 'totp' ? null : $user->two_factor_method,
        ])->save();

        // Turning a factor off must also forget every device that was skipping it — leaving
        // those behind would mean "I disabled 2FA" quietly left a week of skipped codes
        // outstanding on machines the user may no longer have.
        \App\Support\TrustedDevice::revokeAll($user);

        if ($wasEnabled) {
            AuditLog::record('2fa_disable', 'member='.$user->id, $user->id, $request->ip());
        }

        return redirect()->route('two-factor.show')
            ->with('status', 'Two-factor authentication has been disabled.');
    }

    /** 8 single-use, human-friendly recovery codes (e.g. `AB12CD34-EF56GH78`). */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::upper(Str::random(8).'-'.Str::random(8)))
            ->all();
    }

    /** Strip spaces so "123 456" from an authenticator display still verifies. */
    private function normalizeCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? $code;
    }
}
