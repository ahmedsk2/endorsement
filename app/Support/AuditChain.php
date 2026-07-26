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
    public const VERSION = 3;

    /** v1: unkeyed sha256. Kept so pre-2026-07-26 rows still verify rather than alarming. */
    public const VERSION_UNKEYED = 1;

    /**
     * The exact bytes that get hashed for a row. ONE definition, used by both the writer
     * (AuditLog::record) and the verifier (audit:verify).
     *
     * It lived in two places before, and they drifted: setting APP_TIMEZONE changed how the
     * verifier rendered a timestamp but not how the writer had, so `audit:verify` announced
     * that the entire trail had been tampered with. Nothing had been. A tamper-evidence
     * control that cries wolf is worse than none — it trains its only reader to ignore it.
     *
     * The timestamp is therefore handled per version:
     *
     *  - v1/v2 hashed an ISO-8601 rendering of a UTC instant, because the app ran on UTC.
     *    Their stored `created_at` MEANS UTC, so it is parsed as UTC — never in whatever
     *    timezone the app happens to be configured for today.
     *  - v3 hashes the stored datetime VERBATIM. No parsing, so there is no timezone left to
     *    reinterpret it with, and no future APP_TIMEZONE change can invalidate history again.
     */
    public static function canonical(
        ?int $userId,
        string $action,
        ?string $detail,
        ?string $ip,
        \DateTimeInterface|string $createdAt,
        int $version = self::VERSION,
    ): string {
        $stored = $createdAt instanceof \DateTimeInterface
            ? $createdAt->format('Y-m-d H:i:s')
            : $createdAt;

        $time = $version >= 3
            ? $stored
            : \Illuminate\Support\Carbon::parse($stored, 'UTC')->toIso8601String();

        return implode('|', [(string) $userId, $action, (string) $detail, (string) $ip, $time]);
    }

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
