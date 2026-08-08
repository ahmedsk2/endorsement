<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one token that tells two customer deployments apart.
 *
 * D11 makes the DATABASE the isolation boundary, so nothing about correctness depends on this
 * value — but the operational surface outside the container does. It names the archive, scopes
 * the archive's own retention sweep, names the host script's config, log and state files, and
 * prefixes the off-host destination. An operator holding a bucket full of ciphertext has
 * nothing else to go on.
 *
 * Read through config, not env(): the entrypoint runs `config:cache` at boot against the real
 * process environment, so the cached value is correct. (BackupRun reads BACKUP_PASSPHRASE via
 * env() for a different reason — it is a secret, and the invariant is about not caching those.)
 */
final class Instance
{
    /** Filename-safe, glob-safe, DNS-label-shaped. Deliberately narrow. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    public static function slug(): string
    {
        $configured = trim((string) config('endorsement.instance.slug', ''));

        if ($configured !== '') {
            if (! preg_match(self::SLUG_PATTERN, $configured)) {
                throw new \InvalidArgumentException(
                    'INSTANCE_SLUG must match '.self::SLUG_PATTERN.' — lowercase letters, digits '
                    .'and hyphens, starting alphanumeric, at most 32 characters. It names files on '
                    .'the host and objects in the backup bucket, so it is not normalised for you.'
                );
            }

            return $configured;
        }

        $derived = Str::slug((string) config('app.name'));

        return $derived !== '' ? substr($derived, 0, 32) : 'instance';
    }

    /**
     * A stable, non-invertible label for the APP_KEY this instance encrypts with, so an
     * operator can pair an archive with the key that opens it WITHOUT holding either. Domain
     * separated so it cannot be compared against a hash of the key computed for any other
     * purpose.
     */
    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', 'endorsement-key-fingerprint:'.(string) config('app.key')), 0, 16);
    }
}
