<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Watch the audit trail for the things it was built to catch.
 *
 * The application records exactly the right events — reads as well as writes, denied
 * access, reversed attestations, role changes — and until now nothing consumed any of
 * them. `audit:verify` checks that the chain is INTACT, which is a different question from
 * whether anything alarming happened inside it. That gap turns every insider scenario in
 * the threat model from "detected and investigated" into "discovered months later, if
 * ever", and PDPL's 72-hour breach clock starts at awareness.
 *
 * Thresholds are deliberately loose. A ward that trips an alert every day teaches its
 * owner to ignore alerts, and this system has one maintainer who is also a doctor. Each
 * rule below is meant to fire rarely enough to be worth reading.
 *
 * Findings carry ids and counts only — never a patient identifier, and never the detail
 * column of the row that triggered them, since `reopen_reason` and friends are free text.
 */
class AuditAnomalies extends Command
{
    protected $signature = 'audit:anomalies {--hours=1 : How far back to look}';

    protected $description = 'Report suspicious patterns in the audit trail';

    /** One clinician reading this many patient records in the window is not a ward round. */
    private const BULK_READ_THRESHOLD = 120;

    /** Printing is the paper exfiltration channel; the bar is lower. */
    private const BULK_PRINT_THRESHOLD = 25;

    /** Repeated refusals mean someone is trying doors. */
    private const DENIED_THRESHOLD = 10;

    public function handle(): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $findings = [];

        foreach ([
            'endorsement_view' => self::BULK_READ_THRESHOLD,
            'endorsement_print' => self::BULK_PRINT_THRESHOLD,
        ] as $action => $threshold) {
            foreach ($this->perUserCounts($action, $since) as $row) {
                if ($row->n >= $threshold) {
                    $findings[] = "user={$row->user_id} performed {$row->n} {$action} in the window (threshold {$threshold})";
                }
            }
        }

        // Any refusal cluster: wrong capability, out-of-scope target, or a failing factor.
        //
        // `2fa_failed` matters most of the four: a password that works followed by a second
        // factor that keeps failing is what a stolen credential looks like meeting the
        // control that stopped it. It was missing while this comment already claimed it.
        foreach (['access_denied', 'user_scope_denied', 'signature_access_denied', '2fa_failed'] as $action) {
            foreach ($this->perUserCounts($action, $since) as $row) {
                if ($row->n >= self::DENIED_THRESHOLD) {
                    $findings[] = "user={$row->user_id} triggered {$row->n} {$action} in the window";
                }
            }
        }

        // The emailed-code failure records NO user_id — it happens before a session exists —
        // so the per-user sweep above skips it entirely and would go on doing so silently.
        // Counted by source address instead, which only became a meaningful signal on
        // 2026-07-27: before that every request resolved to a Cloudflare edge address, so
        // this would have aggregated the whole internet into one bucket.
        foreach ($this->perIpCounts('two_factor_email_failed', $since) as $row) {
            if ($row->n >= self::DENIED_THRESHOLD) {
                $findings[] = "ip={$row->ip} triggered {$row->n} two_factor_email_failed in the window";
            }
        }

        // Single-occurrence events that always deserve a human look.
        foreach ([
            'endorsement_signoff_reopen' => 'a signed attestation was reversed',
            'user_role_change' => 'an account role was changed',
            'admin_bootstrapped' => 'an administrator was created from the console',
            'user_approve_denied_unverified' => 'an unverified registration approval was refused',
            // P1c Decision H: only the SUMMARY row below is watched. The per-person companion
            // action is deliberately absent from this array — a routine annual promotion of
            // forty people would otherwise page an operator forty times for one act, and an
            // alert channel that cries wolf forty times is one nobody reads on the forty-first.
            'person_promotion' => 'a training-level cohort was promoted',
        ] as $action => $meaning) {
            $n = AuditLog::where('action', $action)->where('created_at', '>=', $since)->count();

            if ($n > 0) {
                $findings[] = "{$n} x {$action} — {$meaning}";
            }
        }

        if ($findings === []) {
            $this->info('No anomalies in the window.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->warn($finding);
        }

        OpsAlert::critical(
            'Audit anomalies detected ('.count($findings).')',
            implode("\n", $findings),
        );

        // NOT a failure exit: the command did its job. Returning non-zero would make the
        // scheduler's onFailure fire as well and report the same thing twice.
        return self::SUCCESS;
    }

    /**
     * For pre-authentication events, where there is no user to group by.
     *
     * An IP is personal data under PDPL, but it is already in this table by design and the
     * finding carries nothing else — no account, no name, no detail column.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function perIpCounts(string $action, \DateTimeInterface $since)
    {
        return AuditLog::query()
            ->select('ip', DB::raw('COUNT(*) as n'))
            ->where('action', $action)
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function perUserCounts(string $action, \DateTimeInterface $since)
    {
        return AuditLog::query()
            ->select('user_id', DB::raw('COUNT(*) as n'))
            ->where('action', $action)
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get();
    }
}
