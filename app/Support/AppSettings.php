<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-editable runtime settings (Admin → Settings): what exists, which are
 * SECRETS, and how a stored value overrides the framework config at boot.
 *
 * Rules:
 *  - SECRETS ARE WRITE-ONLY. Stored encrypted at rest (Crypt), never echoed back to a
 *    page, never audited by value. The page only ever learns whether one is set.
 *  - A stored value overrides .env; an absent one leaves the .env value in place, so
 *    a deployment configured by environment keeps working untouched.
 *  - Reads are cached; every write busts the cache.
 */
final class AppSettings
{
    /** Setting key => is it a secret (encrypted at rest, write-only). */
    public const KEYS = [
        'mail_host' => false,
        'mail_port' => false,
        'mail_username' => false,
        'mail_password' => true,
        'mail_encryption' => false,
        'mail_from_address' => false,
        'mail_from_name' => false,
        // Where operational alerts go: a failed nightly backup or a broken audit chain.
        'alert_email' => false,
        'vapid_subject' => false,
        'vapid_public_key' => false,
        'vapid_private_key' => true,
        'remind_delay_minutes' => false,
        'handover_time_morning' => false,
        'handover_time_afternoon' => false,
        // How long an invitation link stays redeemable (AC-02). Like `alert_email` above,
        // it deliberately has NO entry in applyOverrides()'s $map: it is a value read
        // directly (`Invitation::lifetimeDays()`), not an override of any framework config.
        // Adding a mapping for it would be inventing a config path nothing reads.
        'invitation_lifetime_days' => false,
    ];

    private const CACHE_KEY = 'app_settings.all';

    /**
     * @return array<string, string|null> every stored setting, decrypted
     *
     * ONLY THE CIPHERTEXT IS CACHED. Decryption happens per read, outside the cache.
     *
     * Caching the decrypted map put the SMTP password and the VAPID private key in
     * CLEARTEXT into the cache store — which in production is the database, the very same
     * place their encrypted `app_settings` rows live. That handed anyone who could read the
     * database exactly the secrets the encryption-at-rest layer exists to deny them, and it
     * would have persisted forever (rememberForever). Decrypting on read costs microseconds
     * against a handful of keys and keeps plaintext in process memory only.
     */
    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $out = [];

            foreach (AppSetting::query()->get(['key', 'value']) as $row) {
                if (! array_key_exists($row->key, self::KEYS)) {
                    continue;
                }

                $out[$row->key] = $row->value;   // as stored: secrets stay encrypted
            }

            return $out;
        });

        $decrypted = [];

        foreach ($stored as $key => $value) {
            $decrypted[$key] = (self::KEYS[$key] && $value !== null)
                ? Crypt::decryptString($value)
                : $value;
        }

        return $decrypted;
    }

    public static function get(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    /** Store one known key (encrypting secrets); unknown keys are refused loudly. */
    public static function set(string $key, ?string $value): void
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new \InvalidArgumentException("Unknown setting [{$key}].");
        }

        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => (self::KEYS[$key] && $value !== null) ? Crypt::encryptString($value) : $value],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Map the stored settings over the runtime config. Called at boot (AppServiceProvider)
     * and after a save. Only keys that are actually STORED override anything.
     */
    public static function applyOverrides(): void
    {
        // Pre-migration artisan calls (package:discover, migrate itself) must never crash.
        try {
            if (! Schema::hasTable('app_settings')) {
                return;
            }

            $s = self::all();
        } catch (\Throwable) {
            return;
        }

        $map = [
            'mail_host' => 'mail.mailers.smtp.host',
            'mail_port' => 'mail.mailers.smtp.port',
            'mail_username' => 'mail.mailers.smtp.username',
            'mail_password' => 'mail.mailers.smtp.password',
            'mail_encryption' => 'mail.mailers.smtp.encryption',
            'mail_from_address' => 'mail.from.address',
            'mail_from_name' => 'mail.from.name',
            'vapid_subject' => 'endorsement.vapid.subject',
            'vapid_public_key' => 'endorsement.vapid.public_key',
            'vapid_private_key' => 'endorsement.vapid.private_key',
        ];

        foreach ($map as $key => $configPath) {
            if (array_key_exists($key, $s) && $s[$key] !== null && $s[$key] !== '') {
                config()->set($configPath, in_array($key, ['mail_port'], true) ? (int) $s[$key] : $s[$key]);
            }
        }

        // Setting the SMTP credentials is not the same as SELECTING the SMTP transport, and
        // nothing else in this deployment does the second part: mail is deliberately not an
        // environment variable (docs/RUNBOOK-DEPLOY.md), so without this line the owner fills
        // in this screen, sees "Saved", and MAIL_MAILER stays 'log'. Every operational alert
        // — failed backup, broken audit chain, failed retention sweep — is then written to a
        // log file nobody reads, with no error and nothing to notice.
        //
        // Conditioned on the HOST, never on the settings row existing: selecting a transport
        // with nowhere to connect to would turn login, password reset and registration into
        // 500s instead of the silent no-ops they are today.
        if (! empty($s['mail_host'])) {
            config()->set('mail.default', 'smtp');
        }

        if (! empty($s['remind_delay_minutes'])) {
            config()->set('endorsement.remind_delay_minutes', (int) $s['remind_delay_minutes']);
        }

        // Both times must be present to replace the pair — a half-configured pair keeps
        // the deployed defaults rather than silently dropping a shift.
        if (! empty($s['handover_time_morning']) && ! empty($s['handover_time_afternoon'])) {
            config()->set('endorsement.handover_times', [
                $s['handover_time_morning'],
                $s['handover_time_afternoon'],
            ]);
        }
    }
}
