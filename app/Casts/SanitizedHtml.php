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
     * Ceiling on the SANITIZED (post-`RichTextSanitizer::clean()`) PLAINTEXT, in bytes,
     * before it is encrypted — the same shape as `EncryptedJson::MAX_BYTES`, and for the
     * same reason: the guard has to be measured in the same unit as the thing it protects.
     *
     * SPC-RPT-058 (docs/SECURITY-AUDIT-2026-07-26.md) found the validator bounding these
     * fields at `max:20000` CHARACTERS while the database enforced a limit in BYTES —
     * invisible on `TEXT`'s 65,535-byte ceiling for ASCII (20,000 chars = 20,000 bytes, well
     * inside it) but reachable well below 20,000 characters for anything wider: 18,375
     * Arabic characters (2 bytes each), 12,250 CJK/Arabic-presentation characters (3 bytes),
     * 9,187 emoji (4 bytes), or 7,350 plain ASCII `&` characters once the sanitizer expands
     * each to `&amp;`. `2026_08_15_120001_widen_rich_text_handover_columns` raises the
     * column to `MEDIUMTEXT` (16,777,215 bytes), which makes the physical ceiling a non-issue
     * — but the validator must still be bounded in BYTES, not characters, or the exact same
     * class of defect reappears the next time someone raises (or lowers) the number.
     *
     * 100,000 bytes is derived from the sanitizer's OWN worst-case expansion, not chosen as a
     * round number: HTMLPurifier entity-encodes a bare `&` in text content to `&amp;`, a 5x
     * blow-up, the largest expansion any single character in this allow-list can produce (see
     * `RichTextSanitizer`). 100,000 / 5 = 20,000 — so this ceiling admits AT LEAST the
     * original `max:20000`-characters intent for the worst-conceivable content, and admits
     * strictly MORE for every real script: 100,000 ASCII characters, 50,000 Arabic, 33,333
     * CJK/presentation, or 25,000 emoji. No input that passed the old (buggy) rule for its
     * OWN script can newly fail this one.
     *
     * Ciphertext at the ceiling: 100,000 x 1.783 + ~199 bytes envelope overhead (the
     * `EncryptedJson::MAX_BYTES` constant) ~ 178,499 bytes — 1.06% of MEDIUMTEXT's capacity,
     * so unlike `EncryptedJson`'s 32,000-byte ceiling (chosen to nearly fill `TEXT`), this
     * number is NOT trying to maximise column usage; a clinical narrative has no natural
     * upper bound the way a structured field map does, so the ceiling is set by "how much
     * text is a defensible amount to type into one field", with the DB capacity left as
     * enormous headroom rather than a target.
     */
    public const MAX_PLAINTEXT_BYTES = 100_000;

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

        $clean = RichTextSanitizer::clean((string) $value);

        // Belt-and-suspenders, mirroring EncryptedJson::set(): the HTTP boundary
        // (App\Rules\MaxSanitizedBytes, wired into EndorsementController::validateRow())
        // is what turns an over-ceiling submission into a friendly 422 for a browser client.
        // This throw is what stops any OTHER write path — a factory, a console command, a
        // future API — from ever reaching the database with a value that would not have
        // passed validation. The message names only the attribute and a byte count, never
        // the value itself, which may contain PHI.
        if (strlen($clean) > self::MAX_PLAINTEXT_BYTES) {
            throw new \InvalidArgumentException(
                "[{$key}] is ".strlen($clean).' sanitized bytes, over the '
                .self::MAX_PLAINTEXT_BYTES.'-byte ceiling.'
            );
        }

        return Crypt::encryptString($clean);
    }
}
