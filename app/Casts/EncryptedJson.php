<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * `handovers.extra_fields` — one encrypted `{key: value}` map holding whatever CUSTOM
 * fields a unit has defined for itself (design §6.2, "Ceiling 2", via `unit_field_definitions`).
 * It sits beside `mrn`/`patient_name`/`dob`/the rich-text fields in the same encrypted-at-rest
 * boundary and inherits the same read-must-never-throw contract as every other cast in this
 * directory — see `EncryptedString`'s docblock for the full argument. The short version: this
 * column is read once per row on a census page, so one bad row must degrade, never 500 the
 * whole sheet and hide every other child on the unit.
 *
 * Laravel ships three built-ins for this shape — `encrypted:array`, `AsEncryptedArrayObject`,
 * `AsEncryptedCollection` — and all three are unusable here for the same reason: they throw
 * `DecryptException` on ANY value they did not write themselves. A restored row, a hand-inserted
 * fixture, or a value encrypted under a rotated `APP_KEY` would all 500 the census. This cast
 * exists specifically to tolerate all three by degrading instead of throwing.
 *
 * DEGRADATION IS ALL-OR-NOTHING, unlike the per-field columns. `mrn` failing to decrypt only
 * blanks `mrn`; the rest of the row is fine. Here every custom field lives in ONE column, so a
 * row that fails to decrypt loses ALL of its custom fields at once. That is precisely why
 * `get()` returns the `UNREADABLE_KEY` sentinel rather than an empty array on that path — an
 * empty array is indistinguishable from "no custom fields were ever entered", and a renderer
 * keyed on it would show a clean, silently incomplete sheet. The sentinel is what lets the UI
 * show a visible warning instead.
 *
 * Custom-field values are PLAIN TEXT and are NEVER PURIFIED — unlike the four rich-text fields,
 * which go through `SanitizedHtml`. Every consumer must escape on render: `{{ }}` interpolation
 * in Vue, never `v-html`. If a `richtext` field type is ever added to `unit_field_definitions`,
 * this cast must gain `RichTextSanitizer::clean()` on write AND on the legacy-plaintext read
 * fallback below — mirroring `SanitizedHtml`'s own fallback, which purifies there for exactly
 * the reason explained in its docblock (a value that failed to decrypt never went through a
 * `set()`, so it has never been through any allow-list).
 *
 * NOT SEARCHABLE OR INDEXABLE, same trade-off as every other encrypted column here: no SQL
 * range query, sort, or `LIKE` on this column. Nothing in this system does either.
 *
 * DO NOT ADD AN `ALLOWED`-STYLE KEY ALLOW-LIST, mirroring `ExtraRowFields`' own instruction not
 * to remove one. That cast's allow-list exists because its output becomes model ATTRIBUTE
 * NAMES reaching `$handover->update()` unfiltered — a mass-assignment vector. This column's
 * keys are map keys inside ONE attribute (`extra_fields`) and can never become attribute names,
 * so that hazard does not apply. Worse, an allow-list here keyed on `unit_field_definitions`
 * would be actively destructive: retiring a field definition would silently DELETE that value
 * from every historical row the next time it saves. A clinical value must survive the removal
 * of the definition that produced it — it should simply stop rendering, which is what
 * `Unit::fieldDefinitions()`'s `active()` scope already achieves without touching stored data.
 * Bind this cast by BYTE SIZE and VALUE TYPE only, never by key identity.
 *
 * Two consumer hazards, both easy to trip over:
 *
 *  1. Laravel's attribute-cast cache only kicks in when `get()` returns an OBJECT. An array
 *     return (this one) is NOT cached, so `$handover->extra_fields` re-decrypts on EVERY
 *     access. Read it into a local once per request rather than calling it in a loop.
 *  2. `$handover->extra_fields['x'] = 'y'` does NOT work — PHP's "indirect modification of an
 *     overloaded property" notice, which this app's `HandleExceptions` promotes to a thrown
 *     `ErrorException`. Callers must read the array out, mutate the local copy, and reassign
 *     the whole thing: `$map = $handover->extra_fields; $map['x'] = 'y'; $handover->extra_fields = $map;`.
 *
 * @implements CastsAttributes<array<string, string|null>, string|null>
 */
class EncryptedJson implements CastsAttributes
{
    use \App\Casts\Concerns\DetectsForeignCiphertext;

    /**
     * Reserved map key: what `get()` returns instead of a real value when the column could
     * not be decrypted at all (see the class docblock — "degradation is all-or-nothing"). A
     * write payload can never legitimately produce this key, so `set()` strips it rather than
     * storing it, and `get()` strips it from any row that already carries it (e.g. a legacy
     * plaintext fixture that happens to contain the literal string) — the sentinel must mean
     * one thing only: "this row's map is unreadable", never a value a clinician entered.
     */
    public const UNREADABLE_KEY = '__unreadable';

    /**
     * Ceiling on the PLAINTEXT JSON, in bytes, before it is encrypted.
     *
     * Laravel's `Crypt::encryptString()` base64-encodes the AES output, then base64-encodes
     * the whole `{iv,value,mac}` envelope — so ciphertext growth is approximately 1.78x the
     * plaintext plus ~170 bytes of envelope overhead, NOT the ~1.4x the
     * `2026_07_25_150001_widen_handover_columns_for_encryption` migration's docblock claims
     * for single-value columns (that estimate is for a different growth curve). 32,000 bytes
     * of JSON becomes roughly 57,000 bytes of ciphertext — inside MySQL TEXT's 65,535-byte
     * limit with headroom for the row's other columns.
     */
    public const MAX_BYTES = 32_000;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $json = Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            // Same two-situations-look-identical problem as EncryptedString: a value that was
            // never encrypted is legacy data and stays readable; a value that IS a Laravel
            // payload but will not decrypt was encrypted under a key we no longer hold. The
            // second case must surface as the sentinel, never as raw ciphertext rendered as if
            // it were clinical text.
            if ($this->looksEncrypted($value)) {
                return [self::UNREADABLE_KEY => $this->unreadableMarker()];
            }

            $json = (string) $value;
        }

        $decoded = json_decode($json, true, 16);

        if (! is_array($decoded)) {
            // Not a JSON object at all — a bare scalar, a JSON array (sequential keys, none
            // of them a string), or malformed JSON. All degrade to "no custom fields" rather
            // than throwing.
            return [];
        }

        return $this->normalize($decoded, throwOnNonScalarValue: false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // FIRST statement, before any null/empty short-circuit below. If the null check ran
        // first, clearing this column (autosave sending an empty map, or a row being reset)
        // would silently overwrite — and permanently destroy — ciphertext that is still
        // recoverable once the correct APP_KEY is restored. See
        // DetectsForeignCiphertext::assertNotOverwritingForeignCiphertext()'s own docblock.
        $this->assertNotOverwritingForeignCiphertext($key, $attributes);

        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            throw new \InvalidArgumentException(
                "Custom field map for [{$key}] must be an array, ".get_debug_type($value).' given.'
            );
        }

        // Unlike get(), a nested value here THROWS rather than being silently dropped. On
        // read there is no caller left to notify — dropping is the only option that does not
        // 500 a census over one bad element. On write, the caller (the controller) handed us
        // a value it meant to persist; silently discarding it would look like a successful
        // save while quietly losing clinical text. The message names only the attribute and
        // PHP's type of the offending value — never the value itself, and never the map key,
        // which on this column can itself be attacker- or clinician-supplied text.
        $normalized = $this->normalize($value, throwOnNonScalarValue: true, contextKey: $key);

        if ($normalized === []) {
            return null;
        }

        // Stable plaintext: two writes of the same logical map produce the same ciphertext
        // input, and a human decrypting a row for debugging sees a predictable key order.
        ksort($normalized);

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                "Custom field map for [{$key}] is ".strlen($json).' bytes, over the '.self::MAX_BYTES.'-byte ceiling.'
            );
        }

        return Crypt::encryptString($json);
    }

    /**
     * Shared key/value normalization for both directions: drop non-string and empty keys and
     * the reserved sentinel key; for values, preserve `null`, cast booleans to `'1'`/`'0'`,
     * cast other scalars to `string`, and either drop (read) or throw on (write) anything
     * non-scalar — see get()/set() for why the two directions differ there.
     *
     * @param  array<mixed, mixed>  $decoded
     * @return array<string, string|null>
     */
    private function normalize(array $decoded, bool $throwOnNonScalarValue, ?string $contextKey = null): array
    {
        $out = [];

        foreach ($decoded as $mapKey => $mapValue) {
            if (! is_string($mapKey) || $mapKey === '' || $mapKey === self::UNREADABLE_KEY) {
                continue;
            }

            if ($mapValue === null) {
                $out[$mapKey] = null;

                continue;
            }

            if (! is_scalar($mapValue)) {
                if ($throwOnNonScalarValue) {
                    throw new \InvalidArgumentException(
                        "Custom field value for [{$contextKey}] contains a non-scalar entry (".
                        get_debug_type($mapValue).'); nested values are not supported.'
                    );
                }

                continue;
            }

            $out[$mapKey] = is_bool($mapValue) ? ($mapValue ? '1' : '0') : (string) $mapValue;
        }

        return $out;
    }
}
