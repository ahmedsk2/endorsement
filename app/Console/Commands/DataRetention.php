<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\LoginOtp;
use App\Models\PendingRegistration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The disposal half of a retention policy (PDPL Art. 18 / ECC-1: personal data is kept
 * only as long as the purpose requires, and there must be a MECHANISM, not just a
 * document).
 *
 * DELIBERATELY NARROW. It only ever removes operational rows whose purpose has expired:
 *
 *   - abandoned registration requests (nobody approved, address never confirmed),
 *   - expired one-time sign-in codes,
 *   - dead sessions.
 *
 * It NEVER touches handovers, sign-offs or the audit log. Clinical records follow the
 * hospital's medical-records schedule and are the owner's decision with the medical
 * records department — a cron job must not quietly erase a child's handover history, and
 * the audit trail is the thing that proves what happened to it.
 *
 * DRY RUN BY DEFAULT: it prints what it would delete and changes nothing until --force.
 */
class DataRetention extends Command
{
    protected $signature = 'data:retention {--force : Actually delete (default is a dry run)}';

    protected $description = 'Apply the retention schedule to expired operational data (dry run by default)';

    /** Abandoned registration requests. */
    private const PENDING_DAYS = 30;

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $rows = [];

        // 1. Registration requests nobody acted on.
        $pending = PendingRegistration::where('created_at', '<', now()->subDays(self::PENDING_DAYS));
        $rows[] = ['pending_registrations', 'older than '.self::PENDING_DAYS.' days', $pending->count()];

        // 2. Expired one-time codes (they are dead the moment they expire).
        $otps = LoginOtp::where('expires_at', '<', now());
        $rows[] = ['login_otps', 'expired', $otps->count()];

        // 3. Sessions past their lifetime.
        $cutoff = now()->subMinutes((int) config('session.lifetime', 60) * 2)->timestamp;
        $sessions = DB::table('sessions')->where('last_activity', '<', $cutoff);
        $rows[] = ['sessions', 'idle beyond twice the session lifetime', $sessions->count()];

        $this->table(['table', 'rule', $force ? 'deleted' : 'would delete'], $rows);

        if (! $force) {
            $this->info('Dry run — nothing was deleted. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        $deleted = $pending->delete() + $otps->delete() + $sessions->delete();

        // Counts only; the audit trail itself is never pruned here.
        AuditLog::record('data_retention_applied', 'rows='.$deleted, null, null);

        $this->info("Retention applied: {$deleted} row(s) removed. Clinical records and the audit log were not touched.");

        return self::SUCCESS;
    }
}
