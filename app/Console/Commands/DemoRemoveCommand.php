<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use App\Support\Demo\DemoRemovalBlockedException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Remove the demo department, whole or not at all (Munawib ST-05, P1e Task 13).
 *
 * PREVIEW BEFORE CONFIRM, in a terminal. This is a HARD delete with no undo anywhere in the product
 * — neither rota table nor `clinics` soft-deletes, because they are schedule structure and the
 * hash-chained `audit_log` is the history — so the pre-flight is printed BEFORE the question is
 * asked, which is the discipline invariant 12 requires of every bulk operation.
 *
 * THE PRE-FLIGHT PRINTED HERE IS A QUERY, NOT THE OPERATION. It writes no audit row, because asking
 * what would block a removal is not an attempt to remove anything. `DemoDepartment::remove()` runs
 * it again for real and audits BOTH outcomes — `demo_department_remove` and
 * `demo_department_remove_refused` — so the world moving between the preview and the confirmation
 * cannot slip a real row past.
 *
 * DELIBERATELY THIN, like its sibling: the ledger walk, the refusal, the transaction and the audit
 * rows all live in `App\Support\Demo\DemoDepartment`, so this command and Task 14's screen cannot
 * become two behaviours with one name. No environment guard, for Decision F's reason.
 */
class DemoRemoveCommand extends Command
{
    protected $signature = 'demo:remove
                            {--force : Skip the confirmation prompt}
                            {--batch= : Remove a specific batch rather than the most recent}';

    protected $description = 'Remove the demo department and every row it created (Munawib ST-05).';

    public function handle(): int
    {
        $batches = DemoLedger::batches();

        if ($batches === []) {
            $this->error('No demo department exists. Nothing to remove.');

            return self::FAILURE;
        }

        $requested = $this->option('batch');
        $batch = is_string($requested) && $requested !== '' ? $requested : (string) end($batches);

        $rows = DemoLedger::rowsFor($batch);

        if ($rows === []) {
            $this->error("No demo department was recorded under batch [{$batch}].");

            return self::FAILURE;
        }

        $blocked = DemoDepartment::preflight($batch);

        if ($blocked !== []) {
            $this->error('This demo department cannot be removed — real records now reference it:');

            // Tables and counts only, never a name (invariant 9). This output is read over
            // somebody's shoulder as often as not.
            $this->table(['Table', 'Rows'], array_map(
                static fn (array $row): array => [$row['table'], $row['count']],
                $blocked,
            ));

            $this->line('Deal with those rows first (delete or re-point them), then run this again.');

            return self::FAILURE;
        }

        $this->line("Removing demo department {$batch} — ".count($rows).' rows. This cannot be undone.');

        if (! $this->option('force') && ! $this->confirm('Remove it now?', false)) {
            $this->line('Nothing was removed.');

            return self::SUCCESS;
        }

        try {
            $result = DemoDepartment::remove($batch);
        } catch (DemoRemovalBlockedException $e) {
            // Reachable when a real row arrived between the preview above and this call. The
            // refusal is whole and is audited by the writer.
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Removed {$result['rows']} rows.");

        return self::SUCCESS;
    }
}
