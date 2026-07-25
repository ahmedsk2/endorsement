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
        'vapid_subject' => false,
        'vapid_public_key' => false,
        'vapid_private_key' => true,
        'remind_delay_minutes' => false,
        'handover_time_morning' => false,
        'handover_time_afternoon' => false,
    ];

    private const CACHE_KEY = 'app_settings.all';

    /** @return array<string, string|null> every stored setting, decrypted */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $out = [];

            foreach (AppSetting::query()->get(['key', 'value']) as $row) {
                if (! array_key_exists($row->key, self::KEYS)) {
                    continue;
                }

                $out[$row->key] = (self::KEYS[$row->key] && $row->value !== null)
                    ? Crypt::decryptString($row->value)
                    : $row->value;
            }

            return $out;
        });
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
