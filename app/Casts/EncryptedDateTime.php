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
 * instead of `?Carbon`, because the marker is text, not a date. It is NOT only a `->format(...)`
 * hazard — ANY call site that assumed `?Carbon` can break, including one that never calls
 * `->format()` at all. Every known call site, and how each is guarded:
 *
 *  - `EndorsementController::rowsFor()` (`'dob' => ...`) — formats `dob` for the sheet/print
 *    view. Guarded: `is_string($h->dob) ? $h->dob : $h->dob?->format('Y-m-d H:i')`. The nullsafe
 *    operator alone only guards against `null`, not "not an object" — unguarded, this call
 *    fatals with "Call to a member function format() on string" the moment `dob` is the marker.
 *  - `EndorsementController::newDay()` carry-forward (`'dob' => ...` into `Handover::create()`)
 *    — passes the PRIOR day's `dob` to a NEW row. Guarded:
 *    `$row->dob instanceof \DateTimeInterface ? $row->dob : null`. This one does not call
 *    `->format()`, so the nullsafe pattern above does not even apply — the marker STRING was
 *    being handed straight to `Handover::create()`, which routes it through `EncryptedDateTime::
 *    set()` and `Carbon::parse()`, which throws `InvalidFormatException` on the marker text and
 *    500s New Day for the whole unit. Dropping it to `null` costs nothing: `create()` writes a
 *    new row, so the source row's own ciphertext was never at risk from this call site either way.
 *
 * Any new call site must be checked against this same hazard before assuming `?Carbon`.
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
