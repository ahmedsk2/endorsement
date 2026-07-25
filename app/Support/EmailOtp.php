<?php

namespace App\Support;

use App\Models\LoginOtp;
use App\Models\User;
use App\Notifications\LoginOtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * The e-mail second factor: issue a one-time code, mail it, verify it once.
 *
 * Deliberate properties:
 *  - the code is stored HASHED (bcrypt), never in plaintext — a DB read yields nothing usable;
 *  - one live code per user (re-requesting REPLACES, so an old mail cannot be replayed);
 *  - short lifetime and a hard attempt cap, both enforced here rather than at the caller;
 *  - verification is single-use: a successful check deletes the row.
 */
final class EmailOtp
{
    public const LIFETIME_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** Issue (or re-issue) a code and mail it to the account's address. */
    public static function issue(User $user): void
    {
        // 6 digits, cryptographically random, zero-padded so every code is the same length.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        LoginOtp::updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            ],
        );

        $email = $user->member_email;

        if ($email !== null && $email !== '') {
            Notification::route('mail', $email)->notify(new LoginOtpCode($code, $user->full_name));
        }
    }

    /**
     * Verify a submitted code. Returns true exactly once for a correct, unexpired code;
     * every failure counts against the attempt cap, and exhausting it destroys the code.
     */
    public static function verify(User $user, string $submitted): bool
    {
        $otp = LoginOtp::where('user_id', $user->getKey())->first();

        if ($otp === null) {
            return false;
        }

        if ($otp->isExpired() || $otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->delete();

            return false;
        }

        if (! Hash::check(trim($submitted), $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->fresh()?->attempts >= self::MAX_ATTEMPTS) {
                $otp->delete();
            }

            return false;
        }

        // Single use.
        $otp->delete();

        return true;
    }

    public static function clear(User $user): void
    {
        LoginOtp::where('user_id', $user->getKey())->delete();
    }
}
