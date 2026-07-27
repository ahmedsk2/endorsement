<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Escalate an operational failure to a human.
 *
 * The scheduled jobs already detected the right things — a broken audit chain, a night
 * with no recoverable backup — and then wrote `Log::critical` to stderr, where nobody was
 * looking. A control that can tell you the backup failed and doesn't is not much better
 * than one that never checked.
 *
 * Two deliberate properties:
 *
 *  - NEVER PHI. These messages carry a job name and a count, nothing else. They go to an
 *    external mail relay, which is exactly the wrong place for a patient identifier, and
 *    the same rule that keeps clinical data out of audit_log applies here.
 *  - NEVER THROWS. This runs inside a scheduler failure handler. If alerting itself fails
 *    (no SMTP yet, relay down, bad credentials) it must not turn a recoverable job failure
 *    into an unhandled exception that stops the rest of the schedule. The log line is the
 *    floor; the email is the improvement on top of it.
 */
final class OpsAlert
{
    public static function critical(string $subject, string $detail = ''): void
    {
        $line = trim($subject.' '.$detail);

        // The floor: always recorded, regardless of whether mail is configured.
        Log::critical($line);

        $to = self::recipient();

        if ($to === null) {
            return;
        }

        try {
            Mail::to($to)->send(new \App\Mail\OpsAlertMail($subject, $detail));
        } catch (\Throwable $e) {
            // Alerting is best-effort by design; the Log::critical above already landed.
            Log::warning('Operational alert could not be delivered: '.$e->getMessage());
        }
    }

    /** Where alerts go, or null when the owner has not configured one yet. */
    private static function recipient(): ?string
    {
        try {
            $to = AppSettings::get('alert_email');
        } catch (\Throwable) {
            // Pre-migration artisan calls must not explode on a missing settings table.
            return null;
        }

        $to = is_string($to) ? trim($to) : '';

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // No point attempting delivery before SMTP exists; the log line still fires.
        //
        // Ask which TRANSPORT is selected, not whether the smtp host is set. config/mail.php
        // defaults that host to 127.0.0.1, so the old check was true on every deployment
        // that had never configured mail at all — the guard documented above had therefore
        // never once engaged, and every alert was posted to a port nothing listens on.
        return config('mail.default') === 'smtp' ? $to : null;
    }
}
