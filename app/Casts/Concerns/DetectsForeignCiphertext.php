<?php

namespace App\Casts\Concerns;

/**
 * Tells "this row was never encrypted" apart from "this row was encrypted with a key we
 * no longer hold".
 *
 * Both look identical to a plain try/catch around decryption, and treating the second as
 * the first is how encrypted clinical data gets destroyed: the ciphertext is rendered as
 * though it were text, and the next save re-encrypts THAT under the current key. The
 * original plaintext is then unrecoverable, and the backup does not help because it was
 * encrypted with the key that was replaced.
 *
 * A Laravel payload is base64-encoded JSON carrying iv/value/mac. Legacy clinical text is
 * not, so the shape is a reliable discriminator.
 */
trait DetectsForeignCiphertext
{
    /** True when $value has the shape of a Laravel encrypted payload. */
    protected function looksEncrypted(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    /**
     * Refuse to overwrite a value that is encrypted but not decryptable by us.
     *
     * Failing the write is what keeps the situation recoverable: the row survives intact
     * and decrypts again the moment the correct APP_KEY is restored. Letting the write
     * through would be silent, permanent data loss on a paediatric clinical record.
     */
    protected function assertNotOverwritingForeignCiphertext(string $key, array $attributes): void
    {
        $existing = $attributes[$key] ?? null;

        if (! $this->looksEncrypted($existing)) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Crypt::decryptString((string) $existing);
        } catch (\Throwable) {
            throw new \RuntimeException(
                "Refusing to overwrite [{$key}]: the stored value is encrypted with a "
                .'different APP_KEY. Restore the original key before editing, or the '
                .'existing clinical text will be permanently unrecoverable.'
            );
        }
    }

    /** What a clinician sees instead of ciphertext masquerading as clinical text. */
    protected function unreadableMarker(): string
    {
        return '[unreadable — encrypted with a different key]';
    }
}
