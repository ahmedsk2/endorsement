<?php

namespace App\Support;

/**
 * The keyed hash behind the audit trail.
 *
 * The chain used to be a bare `sha256(prev_hash . canonical)`. Every input lived in the
 * audit_log table and the algorithm was public, so anyone able to UPDATE or DELETE those
 * rows — the application's own database credential, a restored dump edited in place, an
 * insider — could alter a row, recompute every subsequent hash with the same formula
 * `audit:verify` uses, and be told "chain intact". Tamper-EVIDENCE requires something the
 * tamperer does not hold.
 *
 * The key is therefore NOT in the database. Preference order:
 *
 *  1. `AUDIT_KEY`, if the owner sets one. This is the strong form: compromising the
 *     application does not also give you the ability to forge its history, because the
 *     audit key and APP_KEY are separate secrets.
 *  2. Otherwise derived from `APP_KEY` via HKDF with a fixed context string. Weaker —
 *     app-level compromise reaches both — but it closes the threat that was actually
 *     modelled (database access ALONE), and it needs no new secret, so no deployment can
 *     accidentally ship with an unkeyed chain.
 *
 * Rows carry a `hash_version` so a key or algorithm change never invalidates history that
 * was correctly recorded under the previous scheme.
 */
final class AuditChain
{
    /** Bump when the algorithm changes; old rows keep verifying under their own version. */
    public const VERSION = 2;

    /** v1: unkeyed sha256. Kept so pre-2026-07-26 rows still verify rather than alarming. */
    public const VERSION_UNKEYED = 1;

    public static function hash(?string $prevHash, string $canonical, int $version = self::VERSION): string
    {
        $payload = ((string) $prevHash).$canonical;

        if ($version === self::VERSION_UNKEYED) {
            return hash('sha256', $payload);
        }

        return hash_hmac('sha256', $payload, self::key());
    }

    /**
     * A row with no recorded version predates the keyed chain.
     */
    public static function versionOf(mixed $stored): int
    {
        return $stored === null ? self::VERSION_UNKEYED : (int) $stored;
    }

    private static function key(): string
    {
        $explicit = (string) env('AUDIT_KEY', '');

        if ($explicit !== '') {
            return $explicit;
        }

        $appKey = (string) config('app.key');

        // Strip Laravel's base64: marker so the derivation runs over the real key bytes.
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        // Domain-separated: the derived audit key is useless for decrypting PHI and vice
        // versa, so one does not substitute for the other if either leaks.
        return hash_hkdf('sha256', $appKey, 32, 'endorsement-audit-chain-v2');
    }
}
