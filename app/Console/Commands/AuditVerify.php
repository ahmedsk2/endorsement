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

        // Chunk in id order; the chain is defined by insertion order.
        $broken = null;

        DB::table('audit_log')->orderBy('id')->chunk(500, function ($rows) use (&$prevHash, &$count, &$broken) {
            foreach ($rows as $row) {
                // Row's recorded prev_hash must equal the hash of the row before it.
                if ((string) $row->prev_hash !== (string) $prevHash) {
                    $broken = $row->id;

                    return false;
                }

                $canonical = implode('|', [
                    (string) $row->user_id,
                    (string) $row->action,
                    (string) $row->detail,
                    (string) $row->ip,
                    // Stored as UTC in the DB; canonicalised exactly as AuditLog::record did.
                    \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
                ]);

                // Verify each row under the algorithm it was WRITTEN with, so introducing
                // the keyed chain does not retroactively declare valid history broken.
                $expected = \App\Support\AuditChain::hash(
                    $row->prev_hash,
                    $canonical,
                    \App\Support\AuditChain::versionOf($row->hash_version ?? null),
                );

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
