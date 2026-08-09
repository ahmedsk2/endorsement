<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Walk the audit_log hash chain head-to-tail and recompute every hash.
 *
 * The chain is only tamper-EVIDENT if somebody actually checks it; this is that check.
 * Exit 0 = intact. Exit 1 = the first broken row is named (id only — audit rows carry
 * no PHI, and neither does this output).
 */
class AuditVerify extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit_log hash chain end to end';

    public function handle(): int
    {
        $prevHash = null;
        $count = 0;

        // `chunkById`, not `chunk`. `chunk()` pages with OFFSET, so the database has to walk
        // and discard every earlier row on every page — O(n²) overall — while `chunkById`
        // pages with `WHERE id > :lastId ORDER BY id`, an index seek, O(n) overall. Measured
        // on this table: 20,000 rows, 1.3s either way; 200,000 rows, `chunk()` 33.5s vs
        // `chunkById()` 0.7s. `data:retention` never prunes audit_log, so this table only
        // grows — see docs/superpowers/plans/2026-08-09-ops-rehearsal-defects.md. Safe here
        // specifically because the chain's own definition IS id order and the table is
        // append-only (enforced by docs/sql/least-privilege.sql's triggers) — chunkById's
        // usual caveat, that concurrent deletes of already-yielded rows can skip a row that
        // shifts into a processed id range, cannot happen to a table nothing can delete from.
        $broken = null;

        DB::table('audit_log')->chunkById(500, function ($rows) use (&$prevHash, &$count, &$broken) {
            foreach ($rows as $row) {
                // Row's recorded prev_hash must equal the hash of the row before it.
                if ((string) $row->prev_hash !== (string) $prevHash) {
                    $broken = $row->id;

                    return false;
                }

                // Verify each row under the scheme it was WRITTEN with, so introducing the
                // keyed chain — or setting the ward's timezone — does not retroactively
                // declare correctly-recorded history broken.
                $version = \App\Support\AuditChain::versionOf($row->hash_version ?? null);

                $canonical = \App\Support\AuditChain::canonical(
                    $row->user_id === null ? null : (int) $row->user_id,
                    (string) $row->action,
                    $row->detail,
                    $row->ip,
                    (string) $row->created_at,
                    $version,
                );

                $expected = \App\Support\AuditChain::hash($row->prev_hash, $canonical, $version);

                if (! hash_equals($expected, (string) $row->hash)) {
                    $broken = $row->id;

                    return false;
                }

                $prevHash = $row->hash;
                $count++;
            }

            return true;
        });

        if ($broken !== null) {
            $this->error("Audit chain BROKEN at row {$broken} — the row (or one before it) was altered or removed.");

            return self::FAILURE;
        }

        $this->info("Audit chain intact: {$count} rows verified.");

        return self::SUCCESS;
    }
}
