<?php

namespace App\Casts;

use App\Support\RichTextSanitizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * The rich-text handover fields: SANITIZED then ENCRYPTED on write, decrypted on read.
 *
 * Two defences in one cast, in the order that matters:
 *  1. sanitize (HTMLPurifier allow-list) — stored-XSS defence, applied to the plaintext
 *     BEFORE it is ever stored, so what is encrypted is already safe to render;
 *  2. encrypt (APP_KEY, AES-256-CBC + HMAC) — a paediatric handover narrative routinely
 *     names the child, the parents and the diagnosis, so a stolen dump or a compromised
 *     database account must not yield readable clinical text.
 *
 * The trade-off is deliberate and documented in docs/COMPLIANCE.md: these columns can no
 * longer be searched or sorted in SQL. Nothing in this system does either.
 *
 * Rotating APP_KEY without re-encrypting makes this data unreadable — the key custody
 * runbook is not optional.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class SanitizedHtml implements CastsAttributes
{
    use \App\Casts\Concerns\DetectsForeignCiphertext;

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
            // A row written before encryption was introduced (or by a direct SQL insert)
            // is still readable rather than being lost to the clinician who needs it —
            // but it is PURIFIED HERE, because a value that failed to decrypt is by
            // definition one that never went through set(), and therefore has never been
            // through the allow-list. Returning it raw handed unfiltered markup straight
            // to the browser, which is the stored-XSS hole this cast exists to close.
            // Purifying is idempotent, so an already-clean legacy row is unaffected.
            //
            // Unless it is a Laravel payload we simply cannot decrypt — that is a wrong-key
            // situation, not legacy plaintext, and rendering it would show base64 as a
            // clinical narrative and invite a save that destroys the original.
            return $this->looksEncrypted($value)
                ? $this->unreadableMarker()
                : RichTextSanitizer::clean((string) $value);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $this->assertNotOverwritingForeignCiphertext($key, $attributes);

        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(RichTextSanitizer::clean((string) $value));
    }
}
