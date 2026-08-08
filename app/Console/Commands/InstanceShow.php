<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\AppSettings;
use App\Support\Instance;
use Illuminate\Console\Command;

/**
 * "Did I finish provisioning this instance?" — a command with an answer, instead of a
 * checklist line nobody re-reads. The bootstrap sequence (migrate, seed, least-privilege
 * grants, `user:create-admin`, SMTP, alert email, VAPID) is three manual, unordered,
 * unenforced steps: a deploy that stops after `migrate` gives a healthy container, a passing
 * `/up`, and 403s on every capability-gated route; a deploy that stops after `db:seed` has no
 * way in at all.
 *
 * NEVER prints a secret VALUE — only whether one is configured. `APP_KEY` shows only its
 * non-invertible fingerprint (Instance::keyFingerprint()), the same one written into every
 * backup's `.meta.json` sidecar (Task 1), so an operator can pair an archive with the key
 * that opens it without ever holding either.
 *
 * Exits non-zero until `BACKUP_PASSPHRASE`, `mail_host` and `alert_email` are all configured
 * — those three are load-bearing (no backup at all / all mail silently no-ops / a failed
 * backup escalates to a log nobody reads). VAPID absence is silently DEGRADING rather than
 * blocking: the setup wizard's completion check does not require it, so push reminders being
 * unarmed must never be the reason this command reports an otherwise-live instance as unready.
 */
class InstanceShow extends Command
{
    protected $signature = 'instance:show';

    protected $description = "Show this instance's identity and provisioning state (no secret values)";

    public function handle(): int
    {
        $institution = Institution::current();

        $this->line('slug: '.Instance::slug());
        $this->line('institution: '.($institution !== null
            ? "{$institution->code} — {$institution->name}"
            : 'NONE — run `php artisan db:seed --force` first'));
        $this->line('timezone: '.config('app.timezone').' (now: '.now()->toDateTimeString().')');
        $this->line('APP_KEY fingerprint: '.Instance::keyFingerprint());
        $this->newLine();

        $ready = true;

        $ready = $this->report(
            'BACKUP_PASSPHRASE',
            trim((string) env('BACKUP_PASSPHRASE', '')) !== '',
            'backup:run refuses to write an archive at all — there is no nightly backup.',
            required: true,
        ) && $ready;

        $ready = $this->report(
            'mail_host',
            $this->settingIsConfigured('mail_host'),
            "mail.default is still 'log' and all mail silently no-ops (App\\Support\\AppSettings::applyOverrides()).",
            required: true,
        ) && $ready;

        $ready = $this->report(
            'alert_email',
            $this->settingIsConfigured('alert_email'),
            'OpsAlert is log-only: a failed nightly backup or a broken audit chain will escalate to a log nobody reads.',
            required: true,
        ) && $ready;

        $this->report(
            'vapid_public_key',
            $this->settingIsConfigured('vapid_public_key'),
            'Push reminders are unarmed: the setup wizard does not block on this, so the 07:30/15:30 reminders simply never send. Not a reason to call the instance unready.',
            required: false,
        );

        $this->report(
            'vapid_private_key',
            $this->settingIsConfigured('vapid_private_key'),
            'Both VAPID keys are needed together for push to work.',
            required: false,
        );

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    private function settingIsConfigured(string $key): bool
    {
        $value = AppSettings::get($key);

        return $value !== null && trim($value) !== '';
    }

    private function report(string $label, bool $configured, string $consequenceIfMissing, bool $required): bool
    {
        $this->line($label.': '.($configured ? 'set' : 'NOT SET'));

        if (! $configured) {
            $this->line('  '.$consequenceIfMissing);
        }

        return $configured || ! $required;
    }
}
