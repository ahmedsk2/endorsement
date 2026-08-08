<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * An encrypted date/time. Laravel ships `encrypted`, `encrypted:array` and friends but no
 * encrypted date cast, and a birth date is a DIRECT IDENTIFIER — with a name it re-identifies
 * a child on its own, so it does not belong in the clear next to the MRN.
 *
 * Stored as ciphertext of 'Y-m-d H:i:s'; read back as a Carbon instance so every existing
 * `->format(...)` call keeps working. Consequence (deliberate): no SQL range query or sort
 * on this column — nothing in this system does either.
 *
 * Carries the SAME wrong-key protection as `EncryptedString`/`SanitizedHtml` (this cast
 * originally did not, and that was a live gap: `Carbon::parse()` on foreign ciphertext just
 * throws and falls into the same catch as "not a date", so `get()` returned `null` — a blank
 * date of birth, indistinguishable from "never recorded", and the very next save silently
 * re-encrypted that ciphertext under the current key, permanently destroying the original.
 * See `tests/Feature/Security/WrongKeyProtectionTest.php`, the home of this contract.
 *
 * CONSUMER HAZARD, unavoidable given the fix above: `get()` now returns `Carbon|string|null`
 * instead of `?Carbon`, because the marker is text, not a date. Every existing `->format(...)`
 * call on this attribute is written as `$model->dob?->format(...)`, and the nullsafe operator
 * only guards against `null` — it does NOT guard against "not an object". If `dob` is ever the
 * marker string, that call fatals with "Call to a member function format() on string" instead
 * of degrading. `EndorsementController::rowsFor()` (`'dob' => $h->dob?->format('Y-m-d H:i')`)
 * has exactly this shape today and is NOT yet guarded — this is a real, currently-open gap
 * flagged here for whoever next touches that call site: it must check
 * `is_string($h->dob) ? $h->dob : $h->dob?->format(...)` (or equivalent) before this cast's
 * marker path can be considered fully safe end to end.
 *
 * @implements CastsAttributes<Carbon|string|null, string|null>
 */
class EncryptedDateTime implements CastsAttributes
{
    use \App\Casts\Concerns\DetectsForeignCiphertext;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Carbon|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            // Two situations look identical here, same as EncryptedString: a value that was
            // never encrypted is legacy data and stays readable. A value that IS a Laravel
            // payload but will not decrypt was encrypted under a key we no longer hold —
            // showing that as a blank date would look like "never recorded" and invite a
            // save that re-encrypts the ciphertext itself, destroying the original.
            if ($this->looksEncrypted($value)) {
                return $this->unreadableMarker();
            }

            $plain = (string) $value;
        }

        try {
            return Carbon::parse($plain);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $this->assertNotOverwritingForeignCiphertext($key, $attributes);

        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);

        return Crypt::encryptString($date->format('Y-m-d H:i:s'));
    }
}
