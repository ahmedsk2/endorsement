<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * An encrypted string that TOLERATES PLAINTEXT ON READ.
 *
 * Laravel's built-in `encrypted` cast throws "The payload is invalid" on any value it did
 * not write. In a clinical system that is the wrong failure: one row inserted by a DBA, a
 * half-finished backfill, or a restore from a pre-encryption dump would 500 the entire
 * handover sheet — hiding every OTHER child on the unit because of one bad row. Reading
 * such a value back as-is is strictly better: the clinician sees the data, and the next
 * write re-encrypts it.
 *
 * Writes are always encrypted.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedString implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
