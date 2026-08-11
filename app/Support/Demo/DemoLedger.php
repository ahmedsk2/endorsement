<?php

namespace App\Support\Demo;

use App\Models\DemoRow;
use InvalidArgumentException;

/**
 * The ONE writer of `demo_rows` (Munawib ST-05, P1e Decision F).
 * `tests/Feature/Build/DemoRowsAreLedgeredTest.php` asserts at source level that nothing else in
 * app/, database/ or routes/ writes the table.
 *
 * WHAT IT IS FOR. ST-05 asks for a demo department that is "one-click, clearly-labelled and
 * REMOVABLE", and removable is the hard word. This class records every row the demo creates, so
 * removal can prove it removed everything instead of assuming it. Nothing here creates a demo row;
 * the ledger lands and is proved first, because a creator that ships ahead of its ledger is a
 * creator whose first batch is unremovable.
 *
 * IT GENERALISES THREE PATTERNS THIS SCHEMA ALREADY USES, and it is worth naming them because each
 * covers a different third of what a ledger is:
 *
 *   - `applied_role_defaults` — a ledger of "this seed step already ran", and the reason
 *     `AccessControlSeeder` never re-asserts a default an administrator revoked.
 *   - `handovers.legacy_source_table` + `legacy_id` — provenance as a KEY, the legacy import's
 *     idempotency pair, which is exactly the shape of `(table_name, row_id)` below.
 *   - `person_levels.promotion_batch_id` — a batch UUID with no foreign key, because a batch is not
 *     a row anywhere. Same here, and for the same reason.
 *
 * A `demo` BOOLEAN COLUMN ON NINE TABLES WAS REJECTED. It gives every one of them a permanent
 * column for a training feature; `units` has no provenance column of any kind to put beside it
 * (not even `institution_id`); a flag is editable to `false` from any database console, after which
 * removal silently misses the row and still reports success; and, decisively, a column does not
 * ENUMERATE — proving removal complete would still mean scanning every table.
 *
 * A SECOND `institutions` ROW WAS REJECTED BY THE SCHEMA, NOT BY TASTE. D11 keeps `institution_id`
 * as provenance and never a query filter, and the schema is one-way committed against it:
 * `units.code`, `people.email` and `users.member_name` carry institution-blind UNIQUE indexes by
 * design, so a demo unit sharing a real unit's code collides on insert. Worse than not working, it
 * would teach the next reader that filtering by that column is acceptable here.
 *
 * THE LEDGER IS THE MACHINE TRUTH; THE `Demo ` NAME PREFIX (Task 12) IS THE HUMAN LABEL. Neither
 * substitutes for the other. A prefix survives into a CSV export and onto a printed sheet, where no
 * ledger lookup happens; a prefix is also renameable in one click, after which the row would be
 * orphaned forever with no way to tell. Both exist, and they do different jobs.
 *
 * IT WRITES NO AUDIT ROW. The operator actions that create and remove a demo department each write
 * one summary row of their own (Tasks 12 and 14), ids and counts only. Auditing from inside a
 * writer whose callers wrap it in their own transaction is the unwind problem
 * `App\Support\Clinics\ClinicWriter` already carries a docblock about.
 */
final class DemoLedger
{
    /**
     * Record that this row was created by the demo department.
     *
     * The duplicate check below is a COURTESY — it turns a collision into a refusal a caller can
     * read instead of a database exception surfacing through five layers as a 500. The GUARANTEE is
     * the UNIQUE index on `(table_name, row_id)`, and `DemoLedgerTest` asserts that separately by
     * inserting a duplicate around this method; a pre-flight read in PHP is a race and a single
     * writer's promise, and the schema is neither.
     *
     * @throws InvalidArgumentException when the row is already ledgered, whatever the batch
     */
    public static function record(string $table, int $id, string $batch): void
    {
        $already = DemoRow::query()
            ->where('table_name', $table)
            ->where('row_id', $id)
            ->exists();

        if ($already) {
            // No name and no value in the message: ids and table names only, the same rule the
            // audit trail keeps (invariant 9).
            throw new InvalidArgumentException(
                "The demo ledger already holds {$table} row {$id}; a row cannot belong to two batches."
            );
        }

        DemoRow::create([
            'batch_id' => $batch,
            'table_name' => $table,
            'row_id' => $id,
            'created_at' => now(),
        ]);
    }

    /**
     * Every batch the ledger knows about, OLDEST FIRST — so a caller listing them shows the
     * department's demo history in the order it happened.
     *
     * Read whole and de-duplicated in PHP rather than grouped in SQL: a demo department is tens of
     * rows, not millions, and a `GROUP BY` with an aggregate in the ordering clause would be raw
     * SQL for no measurable gain.
     *
     * @return list<string>
     */
    public static function batches(): array
    {
        return DemoRow::query()
            ->orderBy('id')
            ->pluck('batch_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The rows one batch created, NEWEST FIRST — and the order is the whole point of this method
     * rather than an incidental detail. Removal deletes in this order, so a child written after its
     * parent is deleted before it and no restrict-on-delete foreign key fires part-way through a
     * removal that has already started.
     *
     * Ordered by the primary key, not by `created_at`: a batch is written inside one transaction
     * and its rows share a second, so a timestamp sort would return them in whatever order the
     * database felt like.
     *
     * @return list<array{table: string, id: int}>
     */
    public static function rowsFor(string $batch): array
    {
        return DemoRow::query()
            ->where('batch_id', $batch)
            ->orderByDesc('id')
            ->get(['id', 'table_name', 'row_id'])
            ->map(static fn (DemoRow $row): array => [
                'table' => (string) $row->table_name,
                'id' => (int) $row->row_id,
            ])
            ->all();
    }

    /** Whether any demo department exists at all — what the one-click screen asks before offering. */
    public static function has(): bool
    {
        return DemoRow::query()->exists();
    }

    /**
     * Clear the ledger for one batch. It deletes NO demo row anywhere — only this table's record of
     * them.
     *
     * Removal deletes the rows this ledger names and THEN forgets the batch, in that order, so a
     * removal interrupted between the two leaves a ledger naming rows that are already gone —
     * recoverable, and treated as already-removed — rather than orphaned rows nobody can find
     * again. An unknown batch is a no-op: removing nothing removes nothing.
     */
    public static function forgetBatch(string $batch): void
    {
        DemoRow::query()->where('batch_id', $batch)->delete();
    }
}
