<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * ONE definition of what a valid password is, used by registration, reset, the forced
 * change on expiry, and the profile change — so the four can never drift apart (they had:
 * registration demanded four character classes while reset accepted eight lower-case
 * letters, which quietly made reset the weakest way in).
 *
 * The requirements are also what the registration and profile pages checklist live, in
 * the same order. Change them here and update those lists in the same commit.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'confirmed',
            Password::min(self::MIN_LENGTH)->mixedCase()->numbers()->symbols(),
        ];
    }

    /** The same policy where the caller supplies its own required/confirmed rules. */
    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers()->symbols();
    }
}
