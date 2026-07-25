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
 * @implements CastsAttributes<Carbon|null, string|null>
 */
class EncryptedDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            // Pre-encryption row (or a direct SQL insert): still readable.
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
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);

        return Crypt::encryptString($date->format('Y-m-d H:i:s'));
    }
}
