<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Don't ask for a code on this device for a week."
 *
 * The whole of the trust decision lives here, so there is one place to read when asking
 * whether it is safe. Three properties do the work:
 *
 *  1. THE TOKEN IS STORED HASHED. It skips an authentication step, so someone with a copy
 *     of the database must not be able to use it.
 *  2. THE LOOKUP IS SCOPED TO THE USER SIGNING IN. Without that, a cookie minted for one
 *     clinician would wave the next clinician past their own second factor on a shared ward
 *     workstation — the exact machine this feature exists to make tolerable.
 *  3. IT IS REVOCABLE, and revocation is used: changing a password or turning off a second
 *     factor clears every trusted device, because both are things people do when they think
 *     something is wrong.
 *
 * It does NOT extend the session. It only answers "may this login skip the code?" — an idle
 * session still expires, and the account still needs the right password every time.
 */
final class TrustedDevice
{
    public const COOKIE = 'endorsement_device';

    public const DAYS = 7;

    /** Mint one for this user and queue the cookie. */
    public static function remember(User $user, ?string $label = null): void
    {
        $token = Str::random(64);

        DB::table('trusted_devices')->insert([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'label' => $label !== null ? Str::limit($label, 100, '') : null,
            'expires_at' => now()->addDays(self::DAYS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // httpOnly so script cannot read it, secure in production, and SameSite lax rather
        // than strict: this cookie has to arrive on the login navigation itself, which is
        // frequently reached from an emailed link or a home-screen shortcut. It is not a
        // session and grants nothing on its own — the password is still required.
        Cookie::queue(Cookie::make(
            self::COOKIE,
            $token,
            60 * 24 * self::DAYS,
            '/',
            null,
            config('session.secure', true),
            true,
            false,
            'lax',
        ));
    }

    /** Does the cookie on this request name a live, unexpired device for THIS user? */
    public static function trusts(Request $request, User $user): bool
    {
        $token = (string) $request->cookie(self::COOKIE);

        if ($token === '') {
            return false;
        }

        $row = DB::table('trusted_devices')
            ->where('token_hash', hash('sha256', $token))
            ->where('user_id', $user->getKey())          // property 2 — never drop this
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return false;
        }

        DB::table('trusted_devices')->where('id', $row->id)->update(['last_used_at' => now()]);

        return true;
    }

    /**
     * Forget every device for this user. Called wherever someone signals that trust should
     * end: a password change, or turning a second factor off.
     */
    public static function revokeAll(User $user): void
    {
        DB::table('trusted_devices')->where('user_id', $user->getKey())->delete();

        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** How many devices are currently trusted — for the profile to report honestly. */
    public static function countFor(User $user): int
    {
        return DB::table('trusted_devices')
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->count();
    }
}
